<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Lo que el bot hace con cada mensaje que le llega: comandos, botones y vincular al taquero. */
class TelegramBot
{
    /** Mensajes por chat y minuto que se atienden; el resto se ignora (un chat no debe poder saturar al bot). */
    public const MESSAGES_PER_MINUTE = 30;

    /** @param  array<string, mixed>  $update  Un "update" de Telegram tal como llega. */
    public static function handle(array $update): void
    {
        if (! Telegram::enabled()) {
            return;
        }

        if (is_array($update['message'] ?? null)) {
            self::message($update['message']);
        } elseif (is_array($update['callback_query'] ?? null)) {
            self::callback($update['callback_query']);
        }
    }

    // ---- Mensajes y comandos ----

    /** @param  array<string, mixed>  $message */
    protected static function message(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';

        if (! is_int($chatId) || $text === '' || self::tooMany($chatId)) {
            return;
        }

        $private = ($message['chat']['type'] ?? '') === 'private';
        $name = trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')) ?: (string) ($message['chat']['title'] ?? 'Chat');

        if (! preg_match('/^\/(\w+)(?:@\w+)?(?:\s+(.*))?$/su', $text, $match)) {
            if ($private) {
                self::help($chatId);
            }

            return;
        }

        $arguments = trim($match[2] ?? '');

        match (Str::lower($match[1])) {
            'start' => self::start($chatId, $private, $name, $arguments),
            'pedido' => self::order($chatId, $private),
            'menu' => self::menu($chatId),
            'horario' => self::schedule($chatId),
            'estado' => self::status($chatId),
            'ayuda', 'help' => self::help($chatId),
            'vincular' => self::link($chatId, $name, $arguments),
            'desvincular' => self::unlink($chatId),
            'pendientes' => self::staffOnly($chatId, $private, fn () => TelegramAdmin::requested($chatId)),
            'activos' => self::staffOnly($chatId, $private, fn () => TelegramAdmin::active($chatId)),
            'hoy' => self::staffOnly($chatId, $private, fn () => TelegramAdmin::today($chatId)),
            'tienda' => self::staffOnly($chatId, $private, fn () => TelegramAdmin::store($chatId)),
            'panel' => self::staffOnly($chatId, $private, fn () => self::showPanel($chatId)),
            'cliente' => self::staffOnly($chatId, $private, fn () => self::showCustomerView($chatId, $private, $name)),
            default => $private ? self::help($chatId) : null,
        };
    }

    protected static function start(int $chatId, bool $private, string $name, string $payload): void
    {
        // Enlace desde la página de seguimiento: "Recibir avisos por Telegram".
        if ($private && preg_match('/^pedido_([0-9a-f-]{36})$/i', $payload, $match)) {
            self::linkOrder($chatId, $match[1]);

            return;
        }

        if (self::adminView($chatId)) {
            TelegramAdmin::panel($chatId);

            return;
        }

        $first = Telegram::esc(Str::before($name, ' '));
        $store = Store::status();
        $line = $store['open'] ? 'Estamos abiertos' : 'Por ahora estamos cerrados';

        Telegram::send($chatId, 'Hola'.($first !== '' ? ', '.$first : '').'. Bienvenido a '.Telegram::esc(Branding::name()).".\n".$line.'. '.($store['message'] ? Telegram::esc($store['message']).' ' : '')."\nToca Hacer pedido para ver el menú, personalizar tus tacos y pedir.", self::mainKeyboard($private, $chatId));
    }

    protected static function order(int $chatId, bool $private): void
    {
        $store = self::availability();

        Telegram::send($chatId, ($store ? $store."\n" : '').'Abre el menú para armar tu pedido.', self::mainKeyboard($private, $chatId));
    }

    protected static function menu(int $chatId): void
    {
        $products = Product::where('active', true)->orderBy('sort_order')->orderBy('name')->get()->groupBy(fn (Product $p) => $p->category ?: 'Menú');

        if ($products->isEmpty()) {
            Telegram::send($chatId, 'Todavía no hay menú publicado.');

            return;
        }

        $chunks = [];
        $current = '';

        foreach ($products as $category => $group) {
            $header = '<b>'.Telegram::esc($category)."</b>\n";
            $hasHeader = false;

            foreach ($group as $product) {
                $line = Telegram::esc($product->name).'  '.money($product->price).($product->isSoldOut() ? ' (agotado hoy)' : '')."\n";
                $prefix = $hasHeader ? '' : $header;

                if ($current !== '' && mb_strlen($current.$prefix.$line) > 3500) {
                    $chunks[] = rtrim($current);
                    $current = '';
                    $hasHeader = false;
                    $prefix = $header;
                }

                $current .= $prefix.$line;
                $hasHeader = true;
            }

            $current .= "\n";
        }

        if ($current !== '') {
            $chunks[] = rtrim($current);
        }

        foreach ($chunks as $i => $chunk) {
            Telegram::send($chatId, $chunk, $i === array_key_last($chunks) ? self::mainKeyboard(true, $chatId) : null);
        }
    }

    protected static function schedule(int $chatId): void
    {
        $status = Store::status();
        $lines = [$status['open'] ? 'Estamos abiertos'.($status['closes_at'] ? ' hasta las '.Store::label($status['closes_at']->format('H:i')) : '').'.' : 'Estamos cerrados'.($status['opens_at'] ? '. Abrimos el '.mb_strtolower($status['opens_at']->translatedFormat('l')).' a las '.Store::label($status['opens_at']->format('H:i')) : '').'.'];

        if ($status['message']) {
            $lines[] = Telegram::esc($status['message']);
        }

        $lines[] = 'Horario: '.Telegram::esc(Store::summary()).'.';
        $lines[] = Store::onlineEnabled() ? 'Pedidos en línea: activados.' : 'Pedidos en línea: apagados por ahora.';
        $lines[] = Store::deliveryEnabled() ? 'A domicilio: disponible.' : Telegram::esc(Store::deliveryOffMessage());

        Telegram::send($chatId, implode("\n", $lines));
    }

    protected static function status(int $chatId): void
    {
        $order = Order::where('telegram_chat_id', $chatId)->where('source', 'online')->latest('id')->first();

        if (! $order || $order->isTrackingExpired()) {
            Telegram::send($chatId, 'No encuentro un pedido reciente tuyo. Toca Hacer pedido para empezar.', self::mainKeyboard(true, $chatId));

            return;
        }

        $stage = $order->customerStage();

        Telegram::send($chatId, '<b>'.Telegram::esc($stage['title']).'</b>'."\n".Telegram::esc(Str::of($stage['text'])->replace(' Deja esta página abierta: aquí mismo te avisamos.', ' Te avisamos por aquí.')->toString())."\n\nTotal: ".money($order->total)."\nSeguimiento: ".route('pedido', $order->public_token), $order->status === OrderStatus::Requested ? ['inline_keyboard' => [[['text' => 'Cancelar pedido', 'callback_data' => 'can:'.$order->id]]]] : null);
    }

    protected static function help(int $chatId): void
    {
        if (self::adminView($chatId)) {
            TelegramAdmin::help($chatId);
            TelegramAdmin::panel($chatId);

            return;
        }

        $staff = Telegram::isStaff($chatId) ? "\n\nComo taquero, /panel te regresa al panel y /desvincular deja de enviar los pedidos a este chat." : '';

        Telegram::send($chatId, "Esto puedo hacer:\n/pedido para hacer un pedido\n/menu para ver el menú y los precios\n/horario para saber si estamos abiertos\n/estado para ver cómo va tu pedido".$staff, self::mainKeyboard(true, $chatId));
    }

    protected static function link(int $chatId, string $name, string $code): void
    {
        if (Telegram::isStaff($chatId)) {
            Telegram::send($chatId, 'Este chat ya recibe los pedidos.');

            return;
        }

        if ($code === '' || ! Telegram::consumeLinkCode($chatId, $code, $name)) {
            Telegram::send($chatId, 'Código incorrecto o vencido. Genera uno nuevo en Tienda en línea del sistema y escribe /vincular seguido del código.');

            return;
        }

        Telegram::send($chatId, 'Listo: este chat recibirá los pedidos en línea con botones para aceptarlos o rechazarlos.');
        Telegram::applyStaffUi($chatId, true);
        TelegramAdmin::panel($chatId);

        foreach (Order::requested()->with('items')->orderBy('created_at')->limit(10)->get() as $order) {
            TelegramNotifier::sendStaffNotice($order, $chatId);
        }
    }

    protected static function unlink(int $chatId): void
    {
        if (! Telegram::isStaff($chatId)) {
            return;
        }

        Telegram::removeStaff($chatId);
        Telegram::send($chatId, 'Listo: este chat ya no recibe los pedidos.');
    }

    /** Corre un comando del taquero; a cualquier otro chat le dice que no es suyo (solo en privado). */
    protected static function staffOnly(int $chatId, bool $private, callable $run): void
    {
        if (! Telegram::isStaff($chatId)) {
            if ($private) {
                Telegram::send($chatId, 'Ese comando es solo para el taquero.');
            }

            return;
        }

        $run();
    }

    /** ¿Este chat es del taquero y está viendo el panel (no el bot como cliente)? */
    protected static function adminView(int $chatId): bool
    {
        return Telegram::isStaff($chatId) && ! Telegram::usesCustomerView($chatId);
    }

    protected static function showPanel(int $chatId): void
    {
        if (Telegram::usesCustomerView($chatId)) {
            Telegram::setCustomerView($chatId, false);
        }

        TelegramAdmin::panel($chatId);
    }

    /** El taquero ve el bot tal como lo ve un cliente, con un botón para volver al panel. */
    protected static function showCustomerView(int $chatId, bool $private, string $name = ''): void
    {
        if (! Telegram::usesCustomerView($chatId)) {
            Telegram::setCustomerView($chatId, true);
        }

        self::start($chatId, $private, $name, '');
    }

    protected static function linkOrder(int $chatId, string $token): void
    {
        $order = Str::isUuid($token) ? Order::where('public_token', $token)->where('source', 'online')->first() : null;

        if (! $order || $order->isTrackingExpired()) {
            Telegram::send($chatId, 'Ese pedido ya no está disponible.');

            return;
        }

        if ($order->telegram_chat_id && $order->telegram_chat_id !== $chatId) {
            Telegram::send($chatId, 'Ese pedido ya recibe avisos en otro chat.');

            return;
        }

        $order->forceFill(['telegram_chat_id' => $chatId])->saveQuietly();
        Telegram::send($chatId, 'Listo, te avisamos por aquí de tu pedido.');
        self::status($chatId);
    }

    // ---- Botones ----

    /** @param  array<string, mixed>  $query */
    protected static function callback(array $query): void
    {
        $id = (string) ($query['id'] ?? '');
        $chatId = $query['message']['chat']['id'] ?? null;
        $data = is_string($query['data'] ?? null) ? $query['data'] : '';

        if ($id === '' || ! is_int($chatId) || ! preg_match('/^(\w+)(?::(\d{1,12}))?(?::(\d{1,2}))?$/', $data, $match)) {
            return;
        }

        if (self::tooMany($chatId)) {
            Telegram::answerCallback($id);

            return;
        }

        $action = $match[1];
        $orderId = isset($match[2]) ? (int) $match[2] : null;
        $messageId = $query['message']['message_id'] ?? null;

        // Los botones de la bienvenida hacen lo mismo que los comandos.
        if (in_array($action, ['menu', 'horario', 'estado'], true)) {
            Telegram::answerCallback($id);
            self::{$action === 'menu' ? 'menu' : ($action === 'horario' ? 'schedule' : 'status')}($chatId);

            return;
        }

        // Del panel del taquero: cambiar de vista, la tienda y las listas.
        if (in_array($action, ['cv', 'adm'], true)) {
            self::switchView($id, $chatId, $messageId, $action === 'cv', (string) ($query['from']['first_name'] ?? ''));

            return;
        }

        if (in_array($action, TelegramAdmin::PANEL_ACTIONS, true)) {
            TelegramAdmin::panelAction($id, $chatId, $messageId, $action);

            return;
        }

        $order = $orderId ? Order::find($orderId) : null;

        if (! $order) {
            Telegram::answerCallback($id, 'Ese pedido ya no existe.');

            return;
        }

        match (true) {
            in_array($action, ['acc', 'rej', 'rjr', 'bk'], true) => self::staffAction($id, $chatId, $messageId, $action, $order, isset($match[3]) ? (int) $match[3] : null),
            in_array($action, TelegramAdmin::ORDER_ACTIONS, true) => TelegramAdmin::orderAction($id, $chatId, $messageId, $action, $order, isset($match[3]) ? (int) $match[3] : null),
            $action === 'can' => self::customerCancel($id, $chatId, $order),
            default => Telegram::answerCallback($id),
        };
    }

    /** "Ver como cliente" y "Volver al panel": cambian lo que ve el chat del taquero. */
    protected static function switchView(string $id, int $chatId, ?int $messageId, bool $customer, string $name): void
    {
        if (! Telegram::isStaff($chatId)) {
            Telegram::answerCallback($id, 'Este chat no es del taquero.', true);

            return;
        }

        Telegram::answerCallback($id);

        if ($customer) {
            self::showCustomerView($chatId, true, $name);
        } else {
            self::showPanel($chatId);
        }
    }

    protected static function staffAction(string $id, int $chatId, ?int $messageId, string $action, Order $order, ?int $reason): void
    {
        // Solo los chats que el taquero vinculó pueden decidir; el botón de otro chat no sirve aunque conozca el número.
        if (! Telegram::isStaff($chatId)) {
            Telegram::answerCallback($id, 'Este chat no puede responder pedidos.', true);

            return;
        }

        if ($action === 'bk') {
            Telegram::answerCallback($id);
            TelegramAdmin::refresh($chatId, $messageId, $order->loadMissing('items'));

            return;
        }

        if ($order->status !== OrderStatus::Requested) {
            Telegram::answerCallback($id, 'Ese pedido ya no está por aceptar.');
            TelegramNotifier::refreshStaff($order);

            return;
        }

        switch ($action) {
            case 'acc':
                if (OnlineOrder::accept($order)) {
                    Telegram::answerCallback($id, 'Pedido aceptado');
                } else {
                    Telegram::answerCallback($id, 'Ya no se pudo aceptar: caducó o cambió.', true);
                }
                break;

            case 'rej':
                Telegram::answerCallback($id);
                $rows = collect(OnlineOrder::REJECT_REASONS)->map(fn ($text, $i) => [['text' => $text, 'callback_data' => 'rjr:'.$order->id.':'.$i]])->all();
                $rows[] = [['text' => 'Volver', 'callback_data' => 'bk:'.$order->id]];
                if ($messageId) {
                    Telegram::edit($chatId, $messageId, TelegramNotifier::staffText($order)."\n\n<b>Elige el motivo del rechazo</b>", ['inline_keyboard' => $rows]);
                }
                break;

            case 'rjr':
                $text = $reason !== null ? (OnlineOrder::REJECT_REASONS[$reason] ?? null) : null;
                if ($text !== null && OnlineOrder::reject($order, $text)) {
                    Telegram::answerCallback($id, 'Pedido rechazado');
                } else {
                    Telegram::answerCallback($id, 'No se pudo rechazar.', true);
                }
                break;

        }
    }

    protected static function customerCancel(string $id, int $chatId, Order $order): void
    {
        // Solo el chat desde el que se hizo el pedido puede cancelarlo.
        if ($order->telegram_chat_id !== $chatId) {
            Telegram::answerCallback($id, 'Ese pedido no es de este chat.', true);

            return;
        }

        if (OnlineOrder::cancelByCustomer($order)) {
            Telegram::answerCallback($id, 'Pedido cancelado');
        } else {
            Telegram::answerCallback($id, 'Ya no se puede cancelar: la taquería ya respondió.', true);
        }
    }

    // ---- Ayudas ----

    /** @return array<string, mixed> */
    protected static function mainKeyboard(bool $private, ?int $chatId = null): array
    {
        $order = $private
            ? ['text' => 'Hacer pedido', 'web_app' => ['url' => Telegram::miniAppUrl()]]
            : ['text' => 'Hacer pedido', 'url' => url('/')];

        $rows = [
            [$order],
            [['text' => 'Menú', 'callback_data' => 'menu'], ['text' => 'Horario', 'callback_data' => 'horario'], ['text' => 'Mi pedido', 'callback_data' => 'estado']],
        ];

        // El taquero que mira el bot como cliente siempre tiene a la mano cómo volver.
        if ($chatId !== null && Telegram::isStaff($chatId)) {
            $rows[] = [['text' => 'Volver al panel del taquero', 'callback_data' => 'adm']];
        }

        return ['inline_keyboard' => $rows];
    }

    /** Aviso corto si hoy no se puede pedir: cerrado, pedidos apagados o mensaje especial. */
    protected static function availability(): ?string
    {
        $check = Store::canOrderOnline();

        if ($check['ok']) {
            return Store::message() ? Telegram::esc(Store::message()) : null;
        }

        return $check['reason'] === 'online_off'
            ? 'Por ahora no estamos recibiendo pedidos en línea, pero puedes ver el menú.'
            : 'Estamos cerrados en este momento'.(Store::message() ? ': '.Telegram::esc(Store::message()) : '.').' Puedes ver el menú.';
    }

    protected static function tooMany(int $chatId): bool
    {
        $key = 'telegram-chat|'.$chatId;

        if (RateLimiter::tooManyAttempts($key, self::MESSAGES_PER_MINUTE)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
