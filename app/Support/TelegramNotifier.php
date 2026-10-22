<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Avisos del negocio por Telegram: pedidos nuevos al taquero, cambios al cliente, recordatorios y resumen. */
class TelegramNotifier
{
    /** Un pedido en línea nuevo: al taquero con botones para decidir, y al cliente si pidió desde Telegram. */
    public static function placed(Order $order): void
    {
        if (! Telegram::enabled()) {
            return;
        }

        $order = $order->fresh('items') ?? $order;

        foreach (Telegram::staffChats() as $chat) {
            self::sendStaffNotice($order, (int) $chat['id']);
        }

        if ($order->telegram_chat_id) {
            Telegram::send($order->telegram_chat_id, 'Recibimos tu pedido, '.Telegram::esc($order->label).".\nTotal: ".money($order->total).".\nEsperando a que la taquería lo acepte; te avisamos por aquí.\n\nSeguimiento: ".route('pedido', $order->public_token), [
                'inline_keyboard' => [[['text' => 'Cancelar pedido', 'callback_data' => 'can:'.$order->id]]],
            ]);
            Cache::put('telegram:stage:'.$order->id, 'requested', now()->addDays(7));
        }
    }

    /** El pedido cambió (aceptado, rechazado, listo, en camino, entregado): se actualiza el mensaje del taquero y se avisa al cliente. */
    public static function updated(Order $order): void
    {
        if (! Telegram::enabled()) {
            return;
        }

        $order = $order->fresh('items') ?? $order;

        self::refreshStaff($order);

        if (! $order->telegram_chat_id) {
            return;
        }

        $stage = $order->customerStage();

        // Solo se avisa de cada paso una vez, y el de "recibido" ya se mandó al hacer el pedido.
        if ($stage['key'] === 'requested' || Cache::get('telegram:stage:'.$order->id) === $stage['key']) {
            return;
        }

        Cache::put('telegram:stage:'.$order->id, $stage['key'], now()->addDays(7));
        $number = $order->daily_number && $stage['key'] === 'preparing' ? "\nTu número de pedido es el #".$order->daily_number.'.' : '';

        Telegram::send($order->telegram_chat_id, '<b>'.Telegram::esc($stage['title']).'</b>'."\n".Telegram::esc($stage['text']).$number);
    }

    /** Manda al chat del taquero el pedido por aceptar, con sus botones, y guarda el mensaje para poder editarlo. */
    public static function sendStaffNotice(Order $order, int $chatId): void
    {
        $messageId = Telegram::send($chatId, self::staffText($order), self::staffKeyboard($order));

        if ($messageId) {
            DB::table('telegram_notices')->updateOrInsert(['order_id' => $order->id, 'chat_id' => $chatId], ['message_id' => $messageId, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    /** Pone en los mensajes que ya recibió el taquero cómo terminó el pedido y quita los botones. */
    public static function refreshStaff(Order $order): void
    {
        foreach (DB::table('telegram_notices')->where('order_id', $order->id)->get() as $notice) {
            if (Telegram::isStaff((int) $notice->chat_id)) {
                Telegram::edit((int) $notice->chat_id, (int) $notice->message_id, self::staffText($order), self::staffKeyboard($order));
            }
        }
    }

    /** Recordatorio al taquero de un pedido que lleva rato esperando. */
    public static function remind(Order $order): void
    {
        if (! Telegram::enabled() || $order->status !== OrderStatus::Requested) {
            return;
        }

        $minutes = max(1, (int) $order->created_at->diffInMinutes(now()));

        foreach (Telegram::staffChats() as $chat) {
            Telegram::send((int) $chat['id'], '<b>Pedido esperando</b>'."\n".Telegram::esc($order->label).' lleva '.$minutes.' minutos sin respuesta. Total: '.money($order->total).'.', self::staffKeyboard($order));
        }
    }

    /** Resumen del día para el taquero al cerrar. */
    public static function summary(?CarbonInterface $at = null): void
    {
        if (! Telegram::enabled() || Telegram::staffChats() === []) {
            return;
        }

        $text = self::summaryText($at);

        foreach (Telegram::staffChats() as $chat) {
            Telegram::send((int) $chat['id'], $text);
        }
    }

    /** Ventas del día de trabajo del momento indicado; /hoy usa el momento actual. */
    public static function summaryText(?CarbonInterface $at = null): string
    {
        $day = BusinessDay::of($at ?? now());
        $paid = Order::paidBetween(BusinessDay::start($day), BusinessDay::end($day))->get();
        $online = $paid->where('source', 'online');
        $unpaid = Order::where('status', OrderStatus::Open)->count();

        return '<b>Resumen del día</b>'."\n"
            .$paid->count().' ventas por '.money($paid->sum('total'))."\n"
            .'En línea: '.$online->count().' por '.money($online->sum('total'))
            .($unpaid ? "\n".$unpaid.' pedidos todavía sin cobrar.' : '')
            ."\nEl detalle y el corte de caja están en el sistema.";
    }

    public static function staffText(Order $order): string
    {
        $full = self::fullStaffText($order);

        if (mb_strlen($full) <= 3500) {
            return $full;
        }

        $lines = [$order->isOnline()
            ? '<b>Pedido en línea de '.Telegram::esc($order->label).'</b>'
            : '<b>Pedido'.($order->daily_number ? ' #'.$order->daily_number : '').($order->label ? ' de '.Telegram::esc($order->label) : '').'</b>'];

        if ($order->isDelivery()) {
            $lines[] = 'A domicilio: '.Telegram::esc($order->delivery_address);
        } elseif ($order->isOnline()) {
            $lines[] = 'Pasa a recoger';
        } elseif ($service = $order->serviceLabel()) {
            $lines[] = $service;
        }

        if ($phone = $order->customer_phone ?: $order->delivery_phone) {
            $lines[] = 'Teléfono: '.Telegram::esc($phone);
        }

        $lines[] = $order->items->count().' renglones.';
        $lines[] = 'Total: '.money($order->total).self::payment($order);

        if (in_array($order->status, [OrderStatus::Requested, OrderStatus::Open], true)) {
            $lines[] = 'Ver detalle actualizado en Pedidos: '.Telegram::esc(route('pedidos'));
        } elseif (! $order->isOnline() || $order->accepted_at !== null) {
            $lines[] = 'Ver detalle actualizado en Ventas: '.Telegram::esc(route('ventas'));
        }

        if ($footer = self::footer($order)) {
            $lines[] = '<b>'.Telegram::esc($footer).'</b>';
        }

        return implode("\n", $lines);
    }

    protected static function fullStaffText(Order $order): string
    {
        $lines = [$order->isOnline()
            ? '<b>Pedido en línea de '.Telegram::esc($order->label).'</b>'
            : '<b>Pedido'.($order->daily_number ? ' #'.$order->daily_number : '').($order->label ? ' de '.Telegram::esc($order->label) : '').'</b>'];

        if ($order->isDelivery()) {
            $lines[] = 'A domicilio: '.Telegram::esc($order->delivery_address).($order->delivery_notes ? ' ('.Telegram::esc($order->delivery_notes).')' : '');
        } elseif ($order->isOnline()) {
            $lines[] = 'Pasa a recoger';
        } elseif ($service = $order->serviceLabel()) {
            $lines[] = $service;
        }

        if ($phone = $order->customer_phone ?: $order->delivery_phone) {
            $lines[] = 'Teléfono: '.Telegram::esc($phone);
        }

        $lines[] = '';

        foreach ($order->items as $item) {
            $details = array_filter([
                ...collect($item->choices())->pluck('name')->all(),
                ...array_map(fn ($name) => 'sin '.mb_strtolower($name), $item->withoutNames()),
                ...collect($item->extras())->map(fn ($extra) => '+ '.$extra['name'])->all(),
                $item->notes ? '"'.$item->notes.'"' : null,
            ]);
            $lines[] = $item->quantity.' x '.Telegram::esc($item->product_name).($details ? ' ('.Telegram::esc(implode(', ', $details)).')' : '');
        }

        if ($order->notes) {
            $lines[] = '';
            $lines[] = 'Nota: '.Telegram::esc($order->notes);
        }

        $lines[] = '';
        $lines[] = 'Total: '.money($order->total).self::payment($order);

        if ($footer = self::footer($order)) {
            $lines[] = '';
            $lines[] = '<b>'.Telegram::esc($footer).'</b>';
        }

        return implode("\n", $lines);
    }

    /** Cómo va el pedido, para el pie del mensaje del taquero; null mientras sigue por aceptar. */
    public static function footer(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Requested => null,
            $order->status === OrderStatus::Cancelled && $order->cancel_reason === Order::CUSTOMER_CANCELLED => 'Cancelado por el cliente',
            $order->status === OrderStatus::Cancelled && $order->isOnline() && ! $order->accepted_at && $order->cancel_reason === 'Nadie respondió a tiempo' => 'Caducó sin respuesta',
            $order->status === OrderStatus::Cancelled && $order->isOnline() && ! $order->accepted_at => 'Rechazado: '.($order->cancel_reason ?: 'sin motivo'),
            $order->status === OrderStatus::Cancelled => 'Cancelado',
            $order->delivered_at !== null => 'Entregado (#'.$order->daily_number.')',
            $order->dispatched_at !== null => 'En camino (#'.$order->daily_number.')',
            $order->ready_at !== null => 'Listo (#'.$order->daily_number.')',
            default => 'Aceptado: es el #'.$order->daily_number,
        };
    }

    /** Cómo se pagó o si falta cobrar; vacío mientras el pedido no se acepta o si se canceló. */
    protected static function payment(Order $order): string
    {
        return match ($order->status) {
            OrderStatus::Open => ' (por cobrar)',
            OrderStatus::Paid => ' ('.($order->payment_method?->label() ?? 'pagado').')',
            default => '',
        };
    }

    /**
     * Botones del mensaje del taquero según en qué va el pedido: aceptar o rechazar, y ya aceptado,
     * preparar, entregar, cobrar o cancelar.
     *
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}|null
     */
    public static function staffKeyboard(Order $order): ?array
    {
        $id = $order->id;
        $button = fn (string $text, string $action) => ['text' => $text, 'callback_data' => $action.':'.$id];

        if ($order->status === OrderStatus::Requested) {
            return ['inline_keyboard' => [[$button('Aceptar', 'acc'), $button('Rechazar', 'rej')]]];
        }

        if (! in_array($order->status, [OrderStatus::Open, OrderStatus::Paid], true)) {
            return null;
        }

        $rows = [];

        if ($order->delivered_at !== null) {
            $rows[] = [$button('Deshacer entrega', 'und')];
        } elseif ($order->isDelivery()) {
            $rows[] = $order->dispatched_at !== null ? [$button('Entregado', 'dlv')] : array_filter([$order->ready_at === null ? $button('Listo', 'rdy') : null, $button('En camino', 'dsp')]);
        } else {
            $rows[] = array_filter([$order->ready_at === null ? $button('Listo', 'rdy') : null, $button('Entregado', 'dlv')]);
        }

        if ($order->status === OrderStatus::Open) {
            $rows[] = [$button('Cobrar', 'pay'), $button('Cancelar', 'cnc')];
        }

        return ['inline_keyboard' => array_map('array_values', $rows)];
    }
}
