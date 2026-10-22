<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Events\OnlineOrderPlaced;
use App\Events\OnlineOrderUpdated;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pedidos que hacen los clientes desde la página pública. Todos llegan "Por aceptar":
 * el taquero decide si los acepta (entran a Pedidos y Cocina) o los rechaza.
 */
class OnlineOrder
{
    public const MAX_LINES = 30;

    public const MAX_QUANTITY = 20;

    /** Pedidos nuevos por equipo en 10 minutos, y por teléfono en una hora. */
    public const LIMIT_PER_IP = 5;

    public const LIMIT_PER_PHONE = 3;

    /** Intentos (buenos o malos) por equipo en 10 minutos: cada intento cuesta consultas, así que también se limitan. */
    public const LIMIT_ATTEMPTS = 30;

    /** Entre todos los clientes: pedidos nuevos en 10 minutos y pedidos esperando respuesta a la vez. */
    public const LIMIT_GLOBAL = 40;

    public const MAX_WAITING = 25;

    /** Un pedido que nadie acepta ni rechaza en este tiempo se cancela solo y el cliente lo ve. */
    public const EXPIRE_MINUTES = 90;

    /** Días que se guardan teléfono y dirección de un pedido en línea. */
    public const KEEP_CONTACT_DAYS = 60;

    /** Motivos que el taquero elige con un toque al rechazar. */
    public const REJECT_REASONS = [
        'Se acabó algún producto',
        'Ya no alcanzamos a atenderlo',
        'No llegamos a esa zona',
        'No pudimos confirmar tu pedido',
    ];

    /**
     * Recibe lo que mandó el navegador y crea el pedido "Por aceptar". Todo se vuelve a validar y
     * a calcular aquí: precios, extras, cantidades y el servicio disponible salen de la base y los ajustes.
     *
     * @param  array{token?: string, name?: mixed, phone?: mixed, service?: mixed, address?: mixed, references?: mixed, notes?: mixed, lines?: mixed}  $input
     *
     * @throws ValidationException
     */
    public static function place(array $input, string $ip): Order
    {
        $token = is_string($input['token'] ?? null) && Str::isUuid($input['token']) ? $input['token'] : null;

        // Solo se reconoce un envío repetido de un pedido en línea; un token de otra clase de pedido no devuelve nada.
        if ($token && $existing = Order::where('operation_token', $token)->where('source', 'online')->first()) {
            return self::latestCorrection($existing);
        }

        self::throttleAttempts($ip);
        self::assertOpen();
        self::expireStale();

        // Si el pedido se hizo dentro de Telegram, sus datos firmados dicen a qué chat avisarle (no se confía en lo que diga el navegador).
        $telegramChat = Telegram::enabled() ? (Telegram::verifyInitData(is_string($input['telegram_init'] ?? null) ? $input['telegram_init'] : null)['id'] ?? null) : null;

        $name = Names::clean(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '');
        $phone = self::normalizePhone(is_scalar($input['phone'] ?? null) ? (string) $input['phone'] : '');
        $service = $input['service'] ?? null;
        $address = Names::clean(is_scalar($input['address'] ?? null) ? (string) $input['address'] : '');
        $references = Names::clean(is_scalar($input['references'] ?? null) ? (string) $input['references'] : '');
        $notes = Names::clean(is_scalar($input['notes'] ?? null) ? (string) $input['notes'] : '');

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $errors['name'] = 'Escribe tu nombre (hasta 60 letras).';
        }
        if ($phone === null) {
            $errors['phone'] = 'Escribe tu teléfono a 10 dígitos.';
        }
        if (! in_array($service, ['togo', 'delivery'], true)) {
            $errors['service'] = 'Elige si pasas a recoger o si es a domicilio.';
        } elseif ($service === 'delivery' && ! Store::deliveryEnabled()) {
            $errors['service'] = Store::deliveryOffMessage();
        } elseif ($service === 'delivery' && ($address === '' || mb_strlen($address) > 255)) {
            $errors['address'] = 'Escribe la dirección de entrega.';
        }
        if (mb_strlen($references) > 160 || mb_strlen($notes) > 160) {
            $errors['notes'] = 'Las notas pueden tener hasta 160 letras.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (is_array($input['lines'] ?? null) && count($input['lines']) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => 'El pedido puede tener hasta '.self::MAX_LINES.' renglones. Quita algunos productos y vuelve a enviarlo.']);
        }

        $built = OrderBuilder::items(self::cleanLines($input['lines'] ?? []));

        if (isset($built['error'])) {
            throw ValidationException::withMessages(['lines' => $built['error']]);
        }
        if ($built['unavailable']) {
            throw ValidationException::withMessages(['lines' => 'Ya no tenemos: '.implode(', ', $built['unavailable']).'. Quítalo de tu pedido.']);
        }
        if ($built['missing']) {
            throw ValidationException::withMessages(['lines' => 'Falta elegir: '.implode(', ', $built['missing']).'.']);
        }
        if ($built['items'] === []) {
            throw ValidationException::withMessages(['lines' => 'Agrega algo a tu pedido.']);
        }

        $soldOut = Product::whereIn('id', array_column($built['items'], 'product_id'))->get()->filter(fn (Product $p) => $p->isSoldOut())->pluck('name');
        if ($soldOut->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Se acabó hoy: '.$soldOut->join(', ', ' y ').'. Quítalo de tu pedido.']);
        }

        // Si algún precio cambió mientras el cliente armaba su pedido, se le avisa en vez de cobrarle otra cosa.
        $expected = $input['expected_total'] ?? null;
        $total = round($built['total'] + (float) Setting::value('packaging_fee', '0') + ($service === 'delivery' ? (float) Setting::value('delivery_fee', '0') : 0), 2);

        if (is_numeric($expected) && abs((float) $expected - $total) > 0.009) {
            throw ValidationException::withMessages(['prices' => 'Algunos precios cambiaron mientras armabas tu pedido. Vamos a actualizar el menú: revisa el total y vuelve a enviarlo.']);
        }

        // Revisar cupos, contar el intento y crear el pedido va junto y de uno en uno: si no, dos pedidos a la vez pasarían los límites.
        try {
            $order = Cache::lock('online-order-admission', 10)->block(5, function () use ($ip, $phone, $token, $name, $service, $address, $references, $notes, $built, $telegramChat) {
                if (Order::requested()->count() >= self::MAX_WAITING) {
                    throw ValidationException::withMessages(['store' => 'Hay muchos pedidos esperando respuesta. Inténtalo de nuevo en unos minutos.']);
                }

                self::throttle($ip, $phone);

                try {
                    return self::create($token, $name, $phone, $service, $address, $references, $notes, $built, $telegramChat);
                } catch (UniqueConstraintViolationException) {
                    // El mismo formulario llegó dos veces: gana el primero y el otro recibe ese mismo pedido.
                    $existing = $token ? Order::where('operation_token', $token)->where('source', 'online')->first() : null;

                    return $existing ? self::latestCorrection($existing) : self::create(null, $name, $phone, $service, $address, $references, $notes, $built, $telegramChat);
                }
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['store' => 'Estamos recibiendo muchos pedidos. Inténtalo de nuevo en un momento.']);
        }

        self::broadcast(new OnlineOrderPlaced($order));

        return $order;
    }

    /** Un reenvío del formulario original conserva la idempotencia después de corregir el cobro. */
    protected static function latestCorrection(Order $order): Order
    {
        while ($replacement = Order::where('replaces_order_id', $order->id)->where('source', 'online')->first()) {
            $order = $replacement;
        }

        return $order;
    }

    /** @param  array<string, mixed>  $built */
    protected static function create(?string $token, string $name, ?string $phone, string $service, string $address, string $references, string $notes, array $built, ?int $telegramChat = null): Order
    {
        return DB::transaction(function () use ($token, $name, $phone, $service, $address, $references, $notes, $built, $telegramChat) {
            $delivery = $service === 'delivery';
            $order = Order::create([
                'source' => 'online',
                'status' => OrderStatus::Requested,
                'operation_token' => $token,
                'public_token' => (string) Str::uuid(),
                'label' => $name,
                'customer_phone' => $phone,
                'telegram_chat_id' => $telegramChat,
                'service' => $service,
                'delivery_address' => $delivery ? $address : null,
                'delivery_phone' => $delivery ? $phone : null,
                'delivery_notes' => $delivery ? ($references ?: null) : null,
                'notes' => $notes ?: null,
                'packaging_fee' => (float) Setting::value('packaging_fee', '0'),
                'delivery_fee' => $delivery ? (float) Setting::value('delivery_fee', '0') : 0,
                'discount' => 0,
                'tip' => 0,
                'sold_at' => now(),
                'total' => 0,
            ]);

            OrderBuilder::fill($order, $built);

            return $order->fresh(['items']);
        });
    }

    /** El taquero acepta: entra a Pedidos y Cocina como cualquier pedido por cobrar, con su número del día. */
    public static function accept(Order $order): bool
    {
        $accepted = DB::transaction(function () use ($order) {
            // El mismo candado que usa la caja protege la lectura y asignación del número diario.
            DB::select('SELECT pg_advisory_xact_lock(72431901)');
            $locked = Order::whereKey($order->id)->where('status', OrderStatus::Requested)->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            // Un pedido que ya venció no se acepta: el cliente ya lo ve como cancelado o lo verá en su próxima consulta.
            if ($locked->created_at->lt(now()->subMinutes(self::EXPIRE_MINUTES))) {
                $locked->cancel('Nadie respondió a tiempo');

                return null;
            }

            $locked->update([
                'status' => OrderStatus::Open,
                'daily_number' => Order::nextDailyNumber(),
                'accepted_at' => now(),
                'sold_at' => now(),
                'changed_at' => null,
            ]);

            return $locked;
        });

        return (bool) $accepted;
    }

    /** El taquero rechaza con un motivo que el cliente ve. */
    public static function reject(Order $order, string $reason): bool
    {
        $reason = mb_substr(Names::clean($reason), 0, 120);

        return self::cancelRequested($order, $reason !== '' ? $reason : 'No pudimos atender tu pedido');
    }

    /** El cliente cancela mientras nadie lo ha aceptado. */
    public static function cancelByCustomer(Order $order): bool
    {
        return self::cancelRequested($order, Order::CUSTOMER_CANCELLED);
    }

    protected static function cancelRequested(Order $order, string $reason): bool
    {
        $changed = DB::transaction(function () use ($order, $reason) {
            $locked = Order::whereKey($order->id)->where('status', OrderStatus::Requested)->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            $locked->cancel($reason);

            return $locked;
        });

        return (bool) $changed;
    }

    /** Cancela los pedidos en línea que llevan demasiado esperando respuesta. Devuelve cuántos. */
    public static function expireStale(): int
    {
        // Una vez al día se borran los datos de contacto de pedidos viejos: se conserva el nombre y lo vendido, no el teléfono ni la dirección.
        if (Cache::add('online-orders:purged', true, now()->addDay())) {
            Order::where('source', 'online')->whereNotNull('customer_phone')->where('created_at', '<', now()->subDays(self::KEEP_CONTACT_DAYS))
                ->update(['customer_phone' => null, 'delivery_phone' => null, 'delivery_address' => null, 'delivery_notes' => null]);
        }

        $stale = Order::requested()->where('created_at', '<', now()->subMinutes(self::EXPIRE_MINUTES))->get();

        $stale->each(fn (Order $order) => self::cancelRequested($order, 'Nadie respondió a tiempo'));

        return $stale->count();
    }

    /** Avisa a la página del cliente que su pedido cambió (para los cambios que se hacen con consultas directas). */
    public static function notifyCustomer(int $id): void
    {
        $order = Order::find($id);

        if ($order?->isOnline() && $order->public_token) {
            self::broadcast(new OnlineOrderUpdated($order));
        }
    }

    /** Segundos sin intentar avisar después de que el servidor de tiempo real falló. */
    public const REALTIME_PAUSE = 30;

    /**
     * Avisa a las pantallas abiertas y, por Telegram, al taquero y al cliente. Si algo falla, el pedido no se pierde.
     * Dentro de una transacción los avisos salen al confirmarla: ahí también deben quedar protegidos.
     *
     * Telegram va aparte del websocket: si Reverb falla, Laravel no llega a los listeners del evento y los
     * avisos se perderían. Tras una falla de Reverb se deja de intentar un rato, para que cada venta no espere
     * a que venza la conexión; mientras tanto las pantallas se actualizan con su consulta de respaldo.
     */
    public static function broadcast(object $event): void
    {
        DB::afterCommit(function () use ($event) {
            if (! Cache::has('realtime:down')) {
                rescue(fn () => event($event), function (Throwable $e) {
                    Cache::put('realtime:down', true, self::REALTIME_PAUSE);
                    report($e);
                }, report: false);
            }

            rescue(fn () => match (true) {
                $event instanceof OnlineOrderPlaced => TelegramNotifier::placed($event->order),
                $event instanceof OnlineOrderUpdated => TelegramNotifier::updated($event->order),
                default => null,
            }, report: true);
        });
    }

    /** Pasa de "(55) 1234 5678" o "+52 55 1234 5678" a los 10 dígitos; null si no se puede. */
    public static function normalizePhone(string $phone): ?string
    {
        // Solo se aceptan los signos que se usan al escribir un teléfono; con letras u otros símbolos no se adivina.
        if (! preg_match('/^[\d\s().+-]+$/', $phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^[2-9]\d{9}$/', $digits) ? $digits : null;
    }

    /**
     * Solo producto, cantidad e indicaciones: el cliente no puede mandar cortesías ni precios.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function cleanLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        return collect($lines)->filter(fn ($l) => is_array($l) && self::validId($l['product_id'] ?? null))
            ->map(fn (array $l) => [
                'product_id' => (int) $l['product_id'],
                'qty' => self::quantity($l['qty'] ?? null),
                'without' => self::ids($l['without'] ?? []),
                'extras' => self::ids($l['extras'] ?? []),
                'choices' => self::ids($l['choices'] ?? []),
                'notes' => is_scalar($l['notes'] ?? null) ? mb_substr(Names::clean((string) $l['notes']), 0, 120) : null,
            ])->values()->all();
    }

    /** @return array<int, int> */
    protected static function ids(mixed $value): array
    {
        return is_array($value) ? collect($value)->filter(fn ($id) => self::validId($id))->map(fn ($id) => (int) $id)->unique()->take(20)->values()->all() : [];
    }

    protected static function validId(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 1 && (float) $value <= 2147483647;
    }

    protected static function quantity(mixed $value): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return 1;
        }

        if ((float) $value >= self::MAX_QUANTITY) {
            return self::MAX_QUANTITY;
        }

        if ((float) $value <= 1) {
            return 1;
        }

        return (int) $value;
    }

    protected static function assertOpen(): void
    {
        $check = Store::canOrderOnline();

        if ($check['ok']) {
            return;
        }

        throw ValidationException::withMessages(['store' => match ($check['reason']) {
            'online_off' => 'Por ahora no estamos recibiendo pedidos en línea.',
            default => Store::message() ?: 'La taquería está cerrada en este momento.',
        }]);
    }

    /** Cada llamada a place() cuenta, aunque falle: así no se puede gastar consultas de la base con pedidos inválidos. */
    protected static function throttleAttempts(string $ip): void
    {
        $key = 'online-attempt-ip|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::LIMIT_ATTEMPTS)) {
            throw ValidationException::withMessages(['store' => 'Hiciste demasiados intentos. Espera unos minutos e inténtalo de nuevo.']);
        }

        RateLimiter::hit($key, 600);
    }

    protected static function throttle(string $ip, ?string $phone): void
    {
        $limits = [
            'online-order-ip|'.$ip => [self::LIMIT_PER_IP, 600],
            'online-order-phone|'.$phone => [self::LIMIT_PER_PHONE, 3600],
            'online-order-global' => [self::LIMIT_GLOBAL, 600],
        ];

        foreach ($limits as $key => [$attempts]) {
            if (RateLimiter::tooManyAttempts($key, $attempts)) {
                throw ValidationException::withMessages(['store' => 'Hiciste varios pedidos seguidos. Espera unos minutos e inténtalo de nuevo.']);
            }
        }

        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
