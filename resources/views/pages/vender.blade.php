<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Support\BusinessDay;
use App\Support\OrderBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * El pedido se arma en el navegador (Alpine) para que todo sea inmediato.
 * Al cobrar o guardar, el servidor recalcula precios y costos con la base.
 */
new #[Title('Vender')] class extends Component
{
    /** Tope para los montos que se escriben a mano (descuento, propina, envío...). */
    public const MAX_AMOUNT = 100000;

    /** Deshacer es para el error que se nota al cobrar; después se corrige desde Pedidos. */
    public const UNDO_MINUTES = 2;

    /** Pedidos manda aquí el id de una venta cobrada para corregirla (flash de sesión, no un parámetro en la dirección). */
    public const CORRECT_SESSION_KEY = 'vender.corregir';

    /** Pedido guardado que se está editando o cobrando (?orden=12). */
    #[Url(as: 'orden')]
    public ?int $orderId = null;

    /** @var array<int, array{product_id: int, qty: int, without: array<int>, extras: array<int>, notes: string}> */
    public array $lines = [];

    public string $operationToken = '';

    #[Locked]
    public ?int $orderVersion = null;

    #[Locked]
    public ?int $openedOrderId = null;

    public ?string $label = null;

    /** 'here' para comer aquí (lo normal), 'togo' para llevar o 'delivery' a domicilio. */
    public string $service = 'here';

    /** A domicilio. */
    public ?string $address = null;

    public ?string $phone = null;

    public ?string $addressNotes = null;

    public ?string $deliveryFee = null;

    /** Hora de recogida (HH:MM) si el pedido es para más tarde. */
    public ?string $pickupTime = null;

    /** Fecha completa guardada; una hora sin cambios no se reprograma al día siguiente al cobrar. */
    #[Locked]
    public ?string $savedPickupAt = null;

    public string $method = 'cash';

    /** Descuento al pedido: monto en pesos o porcentaje. */
    public ?string $discount = null;

    public string $discountType = 'amount';

    /** Propina en pesos (no cuenta como venta). */
    public ?string $tip = null;

    /** Pago mixto: cuánto en efectivo y con qué se paga el resto. */
    public ?string $cashPart = null;

    public string $otherMethod = 'transfer';

    public ?string $reference = null;

    public ?string $received = null;

    /** Desde Pedidos, "Cobrar" abre directo el cuadro de cobro (?orden=12&cobrar=1). Al terminar se quita de la dirección. */
    #[Url(as: 'cobrar', except: false)]
    public bool $openPay = false;

    /** Número de la venta que se deshizo: al volver a cobrarla conserva su número. */
    public ?int $dailyNumber = null;

    /** Venta que se deshizo: si se vuelve a cobrar, es el mismo pedido del cliente. */
    public ?int $undoneId = null;

    /** Llegó desde Ventas a corregir una venta: el pedido que se estaba armando se aparta mientras tanto. */
    public bool $correcting = false;

    public ?int $lastOrderId = null;

    public float $lastTotal = 0;

    public ?string $lastTitle = null;

    /** Cambio que se dio en la última venta en efectivo, para tenerlo a la vista al cerrar el cobro. */
    public ?float $lastChange = null;

    /** Empaque con el que se guardó el pedido que se edita: si luego cambia en Ajustes, al cliente se le cobra lo que se le dijo. */
    public ?float $savedFee = null;

    public function mount(): void
    {
        $this->operationToken = (string) Str::uuid();
        // Desde Pedidos, "Corregir cobro": la venta se cancela y el pedido abre aquí para cobrarlo de nuevo.
        $correcting = session(self::CORRECT_SESSION_KEY) ? Order::with(['items', 'payments'])->where('status', OrderStatus::Paid)->find(session(self::CORRECT_SESSION_KEY)) : null;

        if ($correcting) {
            $this->orderId = null;
            if ($this->correct($correcting)) {
                $this->correcting = true;
                $this->openPay = true;

                return;
            }
        }

        $order = $this->orderId ? Order::with('items')->where('status', OrderStatus::Open)->find($this->orderId) : null;

        if (! $order) {
            $this->orderId = null;
            $this->openPay = false;

            return;
        }

        $this->fillFrom($order);
    }

    /** Pasa al formulario los datos de un pedido guardado. */
    protected function fillFrom(Order $order): void
    {
        $this->orderVersion = $order->version;
        $this->openedOrderId = $this->orderId;
        $this->lines = OrderBuilder::linesFrom($order);
        $this->label = $order->label;
        $this->service = $order->service ?? 'here';
        $this->address = $order->delivery_address;
        $this->phone = $order->delivery_phone;
        $this->addressNotes = $order->delivery_notes;
        $this->deliveryFee = $order->isDelivery() ? $this->plain($order->delivery_fee) : null;
        $this->discount = (float) $order->discount > 0 ? $this->plain($order->discount) : null;
        $this->discountType = 'amount';
        $this->pickupTime = $order->pickup_at?->format('H:i');
        $this->savedPickupAt = $order->pickup_at?->format('Y-m-d H:i:s');
        $this->savedFee = in_array($order->service, ['togo', 'delivery'], true) ? (float) $order->packaging_fee : null;
    }

    /** 12.50 => "12.5", 20.00 => "20". */
    protected function plain(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.') ?: '0';
    }

    #[Computed]
    public function editing(): ?Order
    {
        return $this->orderId ? Order::find($this->orderId) : null;
    }

    /** Agrupados por categoría, igual que en Productos: uno nuevo queda junto a los de su categoría. */
    #[Computed]
    public function products()
    {
        return Product::with(['ingredients', 'extras', 'optionGroups.choices'])->where('active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->groupBy(fn (Product $p) => $p->category ?: '')
            ->flatten();
    }

    #[Computed]
    public function categories()
    {
        return $this->products->pluck('category')->filter()->unique()->values();
    }

    /** Catálogo para el navegador: precios, "sin" posibles (de la receta) y extras. */
    #[Computed]
    public function catalog(): array
    {
        return $this->products->mapWithKeys(fn ($p) => [$p->id => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'without' => $p->ingredients->filter(fn ($i) => $i->pivot->removable)
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values()->all(),
            'extras' => $p->extras->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'price' => (float) $e->price])->values()->all(),
            'options' => $p->optionGroups->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'required' => $g->required,
                'choices' => $g->choices->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'price' => (float) $c->price])->values()->all(),
            ])->filter(fn ($g) => $g['choices'])->values()->all(),
            'soldOut' => $p->isSoldOut(),
        ]])->all();
    }

    #[Computed]
    public function packagingFee(): float
    {
        $fee = (float) Setting::value('packaging_fee', '0');

        return is_finite($fee) ? round(max(0, $fee), 2) : 0.0;
    }

    #[Computed]
    public function defaultDeliveryFee(): float
    {
        $fee = (float) Setting::value('delivery_fee', '0');

        return is_finite($fee) ? round(max(0, $fee), 2) : 0.0;
    }

    /**
     * Catálogo y cobros actuales. El navegador los pide al abrir el cobro y cada tanto,
     * para no mostrar precios, productos o agotados que cambiaron en otro dispositivo.
     *
     * @return array{catalog: array, fee: float, delivery: float}
     */
    #[Renderless]
    public function freshCatalog(): array
    {
        return ['catalog' => $this->catalog, 'fee' => $this->packagingFee, 'delivery' => $this->defaultDeliveryFee];
    }

    protected function deliveryFeeAmount(): float
    {
        if ($this->service !== 'delivery') {
            return 0.0;
        }

        return is_numeric($this->deliveryFee) ? round(max(0, (float) $this->deliveryFee), 2) : $this->defaultDeliveryFee;
    }

    /**
     * Respuesta de error para el cuadro de cobro. $field es el id del campo con el problema:
     * el navegador lo enfoca para que no haya que buscarlo.
     *
     * @return array{ok: false, error: string, field?: string}
     */
    protected function fail(string $error, ?string $field = null): array
    {
        return $field ? ['ok' => false, 'error' => $error, 'field' => $field] : ['ok' => false, 'error' => $error];
    }

    /** El navegador debe traer el catálogo nuevo antes de intentar este cobro otra vez. */
    protected function pricesChanged(): array
    {
        return [...$this->fail('El total cambió mientras cobrabas. Revisa el nuevo importe y vuelve a confirmar.'), 'prices_changed' => true];
    }

    protected function deliveryError(): ?array
    {
        return $this->service === 'delivery' && trim((string) $this->address) === ''
            ? $this->fail('Escribe la dirección de entrega.', 'address')
            : null;
    }

    /** Montos escritos a mano que no caben en la base. */
    protected function amountError(): ?array
    {
        $fields = [
            'discount' => ['El descuento es demasiado alto', 'discount'],
            'tip' => ['La propina es demasiado alta', 'tip'],
            'deliveryFee' => ['El envío es demasiado alto', 'delivery-fee'],
            'received' => ['Lo recibido es demasiado alto', 'received'],
            'cashPart' => ['El efectivo es demasiado alto', 'cash-part'],
        ];

        foreach ($fields as $property => [$name, $id]) {
            $value = $this->{$property};
            if ($value !== null && $value !== '' && (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > self::MAX_AMOUNT)) {
                return $this->fail($name.' (máximo '.money(self::MAX_AMOUNT).').', $id);
            }
        }

        return null;
    }

    /** @param  array{items: array, unavailable: array<int, string>, missing: array<int, string>}  $built */
    protected function linesError(array $built): ?array
    {
        $error = match (true) {
            isset($built['error']) => $built['error'],
            $built['unavailable'] !== [] => 'Ya no se vende: '.implode(', ', $built['unavailable']).'. Quítalo del pedido.',
            $built['items'] === [] => 'El pedido está vacío.',
            $built['missing'] !== [] => 'Falta elegir: '.implode(', ', $built['missing']).'.',
            default => null,
        };

        return $error ? $this->fail($error) : null;
    }

    /** Texto escrito a mano: sin espacios de más y con el largo que cabe en la base. */
    protected function clip(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Marca o desmarca un producto como agotado por hoy. El navegador ya lo muestra; no hace falta volver a pintar. */
    #[Renderless]
    public function toggleSoldOut(int $id): void
    {
        $product = Product::findOrFail($id);
        $product->update(['sold_out_on' => $product->isSoldOut() ? null : BusinessDay::today()]);
        unset($this->products, $this->catalog);
    }

    protected function pickupAt(): ?\Carbon\Carbon
    {
        if (! $this->pickupTime || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $this->pickupTime)) {
            return null;
        }

        if ($this->savedPickupAt && substr($this->savedPickupAt, 11, 5) === $this->pickupTime) {
            return \Carbon\Carbon::parse($this->savedPickupAt);
        }

        $at = today()->setTimeFromTimeString($this->pickupTime);

        // Una hora que ya pasó hace rato (ej. 00:30 capturado a las 23:00) es para el día siguiente.
        return $at->lt(now()->subHour()) ? $at->addDay() : $at;
    }

    protected function feeFor(string $service, ?Order $source = null): float
    {
        if (! in_array($service, ['togo', 'delivery'], true)) {
            return 0.0;
        }

        $source ??= $this->orderId ? Order::find($this->orderId) : ($this->undoneId ? Order::find($this->undoneId) : null);
        $fee = $source && in_array($source->service, ['togo', 'delivery'], true)
            ? (float) $source->packaging_fee : $this->packagingFee;

        return is_finite($fee) ? round(max(0, $fee), 2) : 0.0;
    }

    /** Descuento en pesos sobre productos + empaque (nunca más que eso). */
    protected function discountFor(float $base): float
    {
        $value = is_numeric($this->discount) ? round(max(0, (float) $this->discount), 2) : 0;
        $amount = $this->discountType === 'percent' ? $base * min(100, $value) / 100 : $value;

        return round(min($base, $amount), 2);
    }

    protected function tipAmount(): float
    {
        return is_numeric($this->tip) ? round(max(0, (float) $this->tip), 2) : 0.0;
    }

    protected function title(Order $order): string
    {
        return '#'.$order->daily_number.($order->label ? ' '.$order->label : '');
    }

    /**
     * Guarda el pedido bajo candado. Devuelve un error legible si cambió el formulario
     * o null si el pedido ya se cobró o se canceló en otro dispositivo.
     */
    protected function store(OrderStatus $status, array $built, ?float $expectedTotal = null): Order|array|null
    {
        if (! Str::isUuid($this->operationToken)) {
            return $this->fail('Algo falló al enviar el pedido. Recarga la página e inténtalo otra vez.');
        }

        return DB::transaction(function () use ($status, $built, $expectedTotal) {
            // El candado de Postgres se comparte entre dispositivos y dura toda la transacción.
            DB::select('SELECT pg_advisory_xact_lock(72431901)');
            if ($existing = $this->completedOperation()) {
                return $existing;
            }

            $undone = null;
            if ($this->orderId) {
                $order = Order::with('items')->whereKey($this->orderId)->where('status', OrderStatus::Open)->lockForUpdate()->first();

                if (! $order) {
                    return null;
                }
                if ($this->openedOrderId !== $order->id || $this->orderVersion !== $order->version) {
                    return $this->fail('Este pedido se modificó desde otro celular. Recarga para ver los cambios antes de cobrar.');
                }
            } else {
                $undone = $this->undoneId ? Order::with('items')->whereKey($this->undoneId)->lockForUpdate()->first() : null;
                if ($this->undoneId && (! $undone || $undone->status !== OrderStatus::Cancelled || $undone->cancel_reason !== Order::CORRECTION || $undone->wasCorrected())) {
                    return $this->fail('Esta venta ya se corrigió desde otro lado. Recarga la página.');
                }
                $keepNumber = $undone && BusinessDay::isCurrent($undone->sold_at);

                $order = new Order([
                    'user_id' => auth()->id(),
                    'operation_token' => $this->operationToken,
                    'replaces_order_id' => $undone?->id,
                    'daily_number' => $keepNumber ? $undone->daily_number : Order::nextDailyNumber(),
                    'source' => $undone?->source ?? 'pos',
                    'customer_phone' => $undone?->customer_phone,
                    'telegram_chat_id' => $undone?->telegram_chat_id,
                    'accepted_at' => $undone?->accepted_at,
                    'notes' => $undone?->notes,
                    'public_token' => $undone?->source === 'online' ? $undone->public_token : null,
                    // Con su mismo número conserva su lugar en la cocina y el tiempo que lleva esperando.
                    'sold_at' => $keepNumber ? $undone->sold_at : now(),
                ]);

                // Si lleva lo mismo, la cocina ya lo tenía: no regresa como nuevo si ya estaba listo, en camino o entregado.
                // Si cambió, la cocina lo ve marcado como cambiado.
                if ($undone && OrderBuilder::signature($undone->items) === OrderBuilder::signature($built['items'])) {
                    $order->fill($undone->only(['ready_at', 'dispatched_at', 'delivered_at']));
                } elseif ($undone) {
                    $order->changed_at = now();
                }
            }

            // Si cambian los productos de un pedido que ya estaba listo o entregado, vuelve a la cocina.
            $changed = $order->exists && OrderBuilder::signature($order->items) !== OrderBuilder::signature($built['items']);

            $paid = $status === OrderStatus::Paid;
            $fee = $this->feeFor($this->service, $order->exists ? $order : $undone);
            $base = $built['total'] + $fee;
            $delivery = $this->service === 'delivery';
            $total = round(max(0, $base - $this->discountFor($base)) + $this->deliveryFeeAmount(), 2);
            if (! is_finite($total) || $base > OrderBuilder::MAX_TOTAL || $total + ($paid ? $this->tipAmount() : 0) > OrderBuilder::MAX_TOTAL) {
                return $this->fail('El total es demasiado alto. Revisa las cantidades.');
            }
            if ($paid && $expectedTotal !== null && abs($expectedTotal - round($total + $this->tipAmount(), 2)) > 0.009) {
                return $this->pricesChanged();
            }
            if ($paid && ($failure = $this->paymentError($total + $this->tipAmount()))) {
                return $failure;
            }

            $order->fill([
                'status' => $status,
                'label' => $this->clip($this->label, 60),
                'service' => in_array($this->service, ['togo', 'here', 'delivery'], true) ? $this->service : null,
                'delivery_address' => $delivery ? $this->clip($this->address, 255) : null,
                'delivery_phone' => $delivery ? $this->clip($this->phone, 30) : null,
                'delivery_notes' => $delivery ? $this->clip($this->addressNotes, 160) : null,
                'delivery_fee' => $this->deliveryFeeAmount(),
                'packaging_fee' => $fee,
                'pickup_at' => $this->pickupAt(),
                'payment_method' => $paid ? $this->method : null,
                'payment_reference' => $paid && $this->method !== 'cash' ? $this->clip($this->reference, 255) : null,
                'paid_at' => $paid ? now() : null,
                'discount' => $this->discountFor($base),
                'tip' => $paid ? $this->tipAmount() : 0,
                'total' => $total,
            ]);

            if ($changed) {
                $order->fill(['ready_at' => null, 'dispatched_at' => null, 'delivered_at' => null, 'changed_at' => now()]);
            }

            // El enlace del cliente tiene dueño único: se mueve al sustituto en la misma transacción.
            if ($undone?->source === 'online' && $undone->public_token) {
                $undone->update(['public_token' => null]);
            }
            $order->save();

            OrderBuilder::fill($order, $built);

            if ($paid) {
                $order->recordPayments($this->method, (float) $this->cashPart, $this->otherMethod, $this->clip($this->reference, 255));
            }

            DB::table('order_operations')->insert(['token' => $this->operationToken, 'order_id' => $order->id]);

            return $order;
        });
    }

    protected function completedOperation(): ?Order
    {
        if (! Str::isUuid($this->operationToken)) {
            return null;
        }

        $id = DB::table('order_operations')->where('token', $this->operationToken)->value('order_id');

        return $id ? Order::find($id) : Order::where('operation_token', $this->operationToken)->first();
    }

    protected function finish(): void
    {
        $this->reset('lines', 'label', 'reference', 'received', 'orderId', 'openPay', 'pickupTime', 'savedPickupAt', 'discount', 'tip', 'cashPart', 'address', 'phone', 'addressNotes', 'deliveryFee', 'dailyNumber', 'undoneId', 'savedFee', 'correcting');
        $this->operationToken = (string) Str::uuid();
        $this->orderVersion = null;
        $this->openedOrderId = null;
        $this->method = 'cash';
        $this->discountType = 'amount';
        $this->otherMethod = 'transfer';
        $this->service = 'here';
        unset($this->editing);
        $this->dispatch('order-reset');
    }

    /** El pedido que se editaba ya no está abierto: se cobró o se canceló en otro dispositivo. */
    protected function orderGone(): array
    {
        $this->finish();
        $this->dispatch('toast', message: 'Ese pedido ya se cobró o se canceló en otro dispositivo.', type: 'error');

        return ['ok' => false, 'closed' => true];
    }

    /**
     * Cambio a dar si se escribió con cuánto pagaron en efectivo. En pago mixto se compara
     * contra la parte en efectivo; con transferencia o tarjeta no hay cambio.
     */
    protected function changeFor(float $due): ?float
    {
        $cashDue = match ($this->method) {
            'cash' => $due,
            'mixed' => round(min($due, max(0, (float) $this->cashPart)), 2),
            default => null,
        };

        if ($cashDue === null || ! is_numeric($this->received)) {
            return null;
        }

        return round((float) $this->received - $cashDue, 2);
    }

    protected function paymentError(float $due): ?array
    {
        if ($this->method === 'mixed') {
            if (! is_numeric($this->cashPart) || (float) $this->cashPart <= 0 || (float) $this->cashPart >= $due) {
                return $this->fail('Escribe cuánto pagan en efectivo. Debe ser menos que el total; el resto va por transferencia o tarjeta.', 'cash-part');
            }
            if (! in_array($this->otherMethod, ['transfer', 'card'], true)) {
                return ['ok' => false, 'error' => 'Elige con qué se paga el resto.'];
            }
        }

        if (($this->changeFor($due) ?? 0) < 0) {
            return $this->fail($this->method === 'mixed'
                ? 'El efectivo recibido no alcanza para la parte en efectivo.'
                : 'El monto recibido no alcanza para el total.', 'received');
        }

        return null;
    }

    /** @return array{ok: bool, error?: string, closed?: bool} */
    public function charge(mixed $expectedTotal = null): array
    {
        if ($order = $this->completedOperation()) {
            return $this->charged($order);
        }

        if (! PaymentMethod::tryFrom($this->method)) {
            return ['ok' => false, 'error' => 'Elige la forma de pago.'];
        }

        if ($expectedTotal !== null && (! is_numeric($expectedTotal) || ! is_finite((float) $expectedTotal) || (float) $expectedTotal < 0)) {
            return $this->fail('El total mostrado no es válido. Actualiza la página y vuelve a cobrar.');
        }

        $built = OrderBuilder::items($this->lines);

        if ($failure = $this->linesError($built) ?? $this->deliveryError() ?? $this->amountError()) {
            return $failure;
        }

        $base = $built['total'] + $this->feeFor($this->service);
        $due = max(0, $base - $this->discountFor($base)) + $this->deliveryFeeAmount() + $this->tipAmount();

        if ($expectedTotal !== null && abs((float) $expectedTotal - round($due, 2)) > 0.009) {
            return $this->pricesChanged();
        }

        if ($failure = $this->paymentError($due)) {
            return $failure;
        }

        $order = $this->store(OrderStatus::Paid, $built, $expectedTotal === null ? null : (float) $expectedTotal);

        if (is_array($order)) {
            return $order;
        }
        if (! $order) {
            return $this->orderGone();
        }

        return $this->charged($order);
    }

    protected function charged(Order $order): array
    {
        $this->lastOrderId = $order->id;
        $this->lastTotal = (float) $order->total + (float) $order->tip;
        $this->lastTitle = $this->title($order);
        $this->lastChange = $this->changeFor((float) $order->total + (float) $order->tip);
        $this->finish();
        $this->dispatch('pending-count', count: Order::pendingCount());

        return ['ok' => true];
    }

    /** Guarda el pedido con su nombre para cobrarlo después. */
    public function saveOpen(): array
    {
        if ($this->completedOperation()) {
            $this->finish();

            return ['ok' => true];
        }

        if (trim((string) $this->label) === '') {
            return $this->fail('Escribe para quién es (un nombre o el carro) para encontrarlo después.', 'label');
        }

        $built = OrderBuilder::items($this->lines);

        if ($failure = $this->deliveryError() ?? $this->linesError($built) ?? $this->amountError()) {
            return $failure;
        }

        $order = $this->store(OrderStatus::Open, $built);

        if (is_array($order)) {
            return $order;
        }
        if (! $order) {
            return $this->orderGone();
        }

        $this->dispatch('toast', message: 'Pedido '.$this->title($order).' guardado sin cobrar');
        $this->dispatch('pending-count', count: Order::pendingCount());
        $this->reset('lastOrderId');
        $this->finish();

        return ['ok' => true];
    }

    public function cancelEdit(): void
    {
        $this->finish();
    }

    /** Cancela la última venta y regresa el pedido completo (servicio, domicilio, descuento, pago) para corregirlo. */
    public function undo(): void
    {
        $order = $this->lastOrderId ? Order::with(['items', 'payments'])->find($this->lastOrderId) : null;

        if ($order && $order->status === OrderStatus::Paid && ! $order->paid_at?->gt(now()->subMinutes(self::UNDO_MINUTES))) {
            $this->dispatch('toast', message: 'Ya pasaron más de '.self::UNDO_MINUTES.' minutos. Corrígela desde Pedidos.', type: 'error');
        } elseif ($order && $order->status === OrderStatus::Paid && (! ($order->paid_at ?? $order->sold_at) || ! BusinessDay::isCurrent($order->countedAt()))) {
            $this->dispatch('toast', message: 'Esta venta ya pertenece al día anterior y no se puede corregir.', type: 'error');
        } elseif ($order && $order->status === OrderStatus::Paid) {
            $this->correct($order);
        }

        $this->reset('lastOrderId', 'lastTotal', 'lastTitle', 'lastChange');
    }

    /** Cancela una venta y regresa el pedido completo (servicio, domicilio, descuento, pago) para corregirlo y volver a cobrarlo. */
    protected function correct(Order $order): bool
    {
        $countedAt = $order->paid_at ?? $order->sold_at;
        $order = DB::transaction(function () use ($order) {
            $current = Order::with(['items', 'payments'])->whereKey($order->id)->lockForUpdate()->first();
            if (! $current || $current->status !== OrderStatus::Paid || ! ($current->paid_at ?? $current->sold_at) || ! BusinessDay::isCurrent($current->countedAt())) {
                return null;
            }
            $current->cancel(Order::CORRECTION);

            return $current;
        });
        if (! $order) {
            $message = ! $countedAt || ! BusinessDay::isCurrent($countedAt)
                ? 'Esta venta ya pertenece al día anterior y no se puede corregir.'
                : 'La venta cambió en otro dispositivo. Recarga la página.';
            $this->dispatch('toast', message: $message, type: 'error');

            return false;
        }
        $this->orderId = null;
        $this->operationToken = (string) Str::uuid();

        $this->fillFrom($order);
        $this->tip = (float) $order->tip > 0 ? $this->plain($order->tip) : null;
        $this->method = $order->payment_method?->value ?? 'cash';
        $this->reference = $order->payment_reference;

        if ($order->payment_method === PaymentMethod::Mixed) {
            $this->cashPart = $this->plain($order->payments->firstWhere('method', PaymentMethod::Cash)?->amount ?? 0);
            $this->otherMethod = $order->payments->first(fn ($p) => $p->method !== PaymentMethod::Cash)?->method->value ?? 'transfer';
        }

        // El cliente ya tiene su número: se conserva si el pedido es de hoy.
        $this->dailyNumber = BusinessDay::isCurrent($order->sold_at) ? $order->daily_number : null;
        $this->undoneId = $order->id;

        $this->dispatch('order-restored', later: (bool) $this->pickupTime, discount: (bool) $this->discount);
        $this->dispatch('toast', message: 'Venta cancelada. El pedido volvió para corregirlo.');
        $this->dispatch('pending-count', count: Order::pendingCount());

        return true;
    }
};
?>

{{--
    El x-data no lleva datos del servidor: si su texto cambia entre renders, Alpine reinicia el
    componente y se pierde el estado del pedido. Los datos iniciales van en data-boot.
--}}
<div
    data-boot="{{ json_encode(['catalog' => $this->catalog, 'fee' => $this->packagingFee, 'delivery' => $this->defaultDeliveryFee]) }}"
    @order-reset.window="later = false; discOpen = false"
    {{-- El recorrido abre y cierra el cuadro de cobro para explicarlo; si no hay pedido, pone uno de ejemplo mientras tanto. --}}
    @tour-open.window="if ($event.detail === 'pedido') { error = ''; startTourDemo(); sheet = true }"
    @tour-close.window="sheet = false; endTourDemo()"
    @order-restored.window="later = $event.detail.later; discOpen = $event.detail.discount; $nextTick(() => saveDraft())"
    x-data="{
        catalog: {},
        fee: 0,
        deliveryDefault: 0,
        stock: false,
        later: false,
        discOpen: false,
        category: '',
        sheet: false,
        editor: null,
        busy: false,
        error: '',
        init() {
            const boot = JSON.parse(this.$root.dataset.boot);
            this.catalog = boot.catalog;
            this.fee = boot.fee;
            this.deliveryDefault = boot.delivery;
            this.later = !! $wire.pickupTime;
            this.discOpen = !! $wire.discount;
            this.sheet = $wire.openPay;
            if ($wire.orderId) this.editSnapshot = this.snapshot();
            if ($wire.correcting) { this.stashDraft(); this.saveDraft() }
            this.restoreDraft();
            // Si la tablet estuvo dormida o pasó un rato, se traen precios y agotados al día.
            this.onVisible = () => document.visibilityState === 'visible' && this.refresh();
            document.addEventListener('visibilitychange', this.onVisible);
            this.timer = setInterval(this.onVisible, 180000);
        },
        destroy() {
            document.removeEventListener('visibilitychange', this.onVisible);
            clearInterval(this.timer);
        },
        fmt(n) {
            n = Math.round(n * 100) / 100;
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
        },
        async refresh() {
            try {
                const r = await $wire.freshCatalog();
                if (r?.catalog) { this.catalog = r.catalog; this.fee = r.fee; this.deliveryDefault = r.delivery; return true }
            } catch (e) {}
            return false;
        },
        openSheet() { this.error = ''; this.sheet = true; this.refresh() },
        // Borrador del pedido en este equipo: sobrevive a una recarga o a salir de Vender sin cobrar.
        draftKey: 'vender:borrador',
        draftFields: ['operationToken', 'label', 'service', 'address', 'phone', 'addressNotes', 'deliveryFee', 'pickupTime', 'discount', 'discountType', 'dailyNumber', 'undoneId'],
        tourDemo: false,
        startTourDemo() {
            const sample = Object.values(this.catalog).find(p => ! p.soldOut && ! this.needsChoice(p.id));
            if (this.count() || ! sample) return;
            this.tourDemo = true;
            $wire.lines = [{ product_id: sample.id, qty: 2, without: [], extras: [], choices: [], notes: '' }];
        },
        endTourDemo() {
            if (! this.tourDemo) return;
            this.tourDemo = false;
            $wire.lines = [];
        },
        saveDraft() {
            if ($wire.orderId || this.tourDemo) return;
            try {
                if (! this.count()) return localStorage.removeItem(this.draftKey);
                const d = { lines: this.list(), at: Date.now() };
                this.draftFields.forEach(k => d[k] = $wire[k]);
                localStorage.setItem(this.draftKey, JSON.stringify(d));
            } catch (e) {}
        },
        clearDraft() { try { localStorage.removeItem(this.draftKey) } catch (e) {} },
        // Al corregir una venta, el pedido que se estaba armando se aparta y vuelve al terminar.
        stashKey: 'vender:apartado',
        stashDraft() {
            try {
                const d = localStorage.getItem(this.draftKey);
                if (d) localStorage.setItem(this.stashKey, d);
            } catch (e) {}
        },
        unstashDraft() {
            let d = null;
            try { d = localStorage.getItem(this.stashKey); localStorage.removeItem(this.stashKey) } catch (e) {}
            if (! d) return;
            try { localStorage.setItem(this.draftKey, d) } catch (e) {}
            this.$nextTick(() => this.restoreDraft('Volvió el pedido que estabas armando.'));
        },
        // Sin mensaje propio, el aviso sale solo la primera vez que se abre la app (tras recargar o volver a entrar), no en cada visita a Vender.
        restoreDraft(message = null) {
            if ($wire.orderId || this.count()) return;
            let d = null;
            try { d = JSON.parse(localStorage.getItem(this.draftKey)) } catch (e) {}
            if (! d?.lines?.length || Date.now() - d.at > 12 * 3600000) return this.clearDraft();
            const lines = d.lines.filter(l => this.catalog[l.product_id]);
            if (! lines.length) return this.clearDraft();
            $wire.lines = lines;
            this.draftFields.forEach(k => { if (d[k] !== undefined) $wire[k] = d[k] });
            this.later = !! d.pickupTime;
            this.discOpen = !! d.discount;
            const notice = message ?? (window.draftNoticeShown ? null : 'Se recuperó el pedido que no se había cobrado.');
            window.draftNoticeShown = true;
            if (notice) setTimeout(() => this.$dispatch('toast', { message: notice }), 300);
        },
        // Renglones del pedido: viven en $wire.lines (se mandan al servidor solo al cobrar o guardar).
        list() { return Array.isArray($wire.lines) ? $wire.lines : Object.values($wire.lines ?? {}) },
        isPlain(l) { return ! l.courtesy && ! l.without?.length && ! l.extras?.length && ! l.choices?.length && ! (l.notes ?? '').trim() },
        same(a, b) {
            const s = x => [...(x ?? [])].map(Number).sort().join('.');
            return a.product_id == b.product_id && !! a.courtesy === !! b.courtesy && s(a.without) === s(b.without) && s(a.extras) === s(b.extras) && s(a.choices) === s(b.choices) && (a.notes ?? '').trim().toLowerCase() === (b.notes ?? '').trim().toLowerCase();
        },
        // Se pregunta solo si hay que escoger entre varias opciones. Con una sola (siempre Coca-Cola) ya va incluida: autoChoices.
        needsChoice(pid) { return (this.catalog[pid]?.options ?? []).some(g => g.required && g.choices.length > 1) },
        autoChoices(pid) { return (this.catalog[pid]?.options ?? []).filter(g => g.required && g.choices.length === 1).map(g => g.choices[0]) },
        qtyOf(pid) { return this.list().filter(l => l.product_id == pid).reduce((n, l) => n + l.qty, 0) },
        tap(pid) {
            if (this.stock) return this.toggleStock(pid);
            if (this.catalog[pid]?.soldOut) return this.$dispatch('toast', { message: this.catalog[pid].name + ' está agotado hoy', type: 'error' });
            this.add(pid);
        },
        toggleStock(pid) {
            this.catalog[pid].soldOut = ! this.catalog[pid].soldOut;
            $wire.toggleSoldOut(pid);
        },
        add(pid) {
            // Si el producto pide elegir algo (ej. refresco del combo), primero se elige.
            if (this.needsChoice(pid)) return this.editNew(pid);
            const lines = this.list().map(l => ({ ...l }));
            const i = lines.findIndex(l => l.product_id == pid && this.isPlain(l));
            i === -1 ? lines.push({ product_id: pid, qty: 1, without: [], extras: [], choices: [], notes: '' }) : lines[i].qty++;
            $wire.lines = lines;
            this.saveDraft();
            navigator.vibrate?.(8);
            this.$nextTick(() => bump(document.getElementById('q-' + pid)));
        },
        removeOne(pid) {
            const lines = this.list().map(l => ({ ...l }));
            let i = lines.findIndex(l => l.product_id == pid && this.isPlain(l));
            if (i === -1) i = lines.map(l => l.product_id == pid).lastIndexOf(true);
            if (i === -1) return;
            if (--lines[i].qty <= 0) lines.splice(i, 1);
            $wire.lines = lines;
            if (! lines.length) this.emptied();
            this.saveDraft();
            this.$nextTick(() => bump(document.getElementById('q-' + pid)));
        },
        setQty(i, input) {
            const lines = this.list().map(l => ({ ...l }));
            if (! lines[i]) return;
            // Borrar el número para escribir otro no debe quitar el renglón: si queda vacío, vuelve la cantidad que tenía.
            if (input.value.trim() === '' || isNaN(Number(input.value))) return input.value = lines[i].qty;
            const qty = Math.min(999, Math.max(0, Math.floor(Number(input.value))));
            qty === 0 ? lines.splice(i, 1) : lines[i].qty = qty;
            $wire.lines = lines;
            if (! lines.length) this.emptied();
            this.saveDraft();
        },
        removeLine(i) {
            const lines = this.list().map(l => ({ ...l }));
            lines.splice(i, 1);
            $wire.lines = lines;
            if (! lines.length) this.emptied();
            this.saveDraft();
        },
        // Sin productos ya no es la venta que se deshizo: el siguiente pedido lleva número nuevo.
        emptied() { this.sheet = false; $wire.dailyNumber = null; $wire.undoneId = null },
        unit(l) {
            if (l.courtesy) return 0;
            const p = this.catalog[l.product_id];
            const choicePrice = (l.choices ?? []).reduce((t, id) => t + (p?.options.flatMap(g => g.choices).find(c => c.id == id)?.price ?? 0), 0)
                + this.autoChoices(l.product_id).filter(c => ! (l.choices ?? []).some(x => x == c.id)).reduce((t, c) => t + c.price, 0);
            return (p?.price ?? 0) + choicePrice + (l.extras ?? []).reduce((t, id) => t + (p?.extras.find(e => e.id == id)?.price ?? 0), 0);
        },
        mods(l) {
            const p = this.catalog[l.product_id];
            return {
                choices: [...this.autoChoices(l.product_id).filter(c => ! (l.choices ?? []).some(x => x == c.id)).map(c => c.name), ...(l.choices ?? []).map(id => p?.options.flatMap(g => g.choices).find(c => c.id == id)?.name)].filter(Boolean),
                without: (l.without ?? []).map(id => 'Sin ' + (p?.without.find(w => w.id == id)?.name ?? '').toLowerCase()),
                extras: (l.extras ?? []).map(id => p?.extras.find(e => e.id == id)?.name).filter(Boolean),
                notes: (l.notes ?? '').trim(),
                courtesy: !! l.courtesy,
            };
        },
        count() { return this.list().reduce((n, l) => n + l.qty, 0) },
        packaging() { return ['togo', 'delivery'].includes($wire.service) ? ($wire.savedFee ?? this.fee) : 0 },
        shipping() {
            if ($wire.service !== 'delivery' || ! this.count()) return 0;
            const v = $wire.deliveryFee;
            return v === null || v === '' || isNaN(v) ? this.deliveryDefault : Math.max(0, Number(v));
        },
        r2(n) { return Math.round((n + Number.EPSILON) * 100) / 100 },
        subtotal() { return this.r2(this.list().reduce((t, l) => t + this.unit(l) * l.qty, 0) + (this.count() ? this.packaging() : 0)) },
        discountAmt() {
            const v = this.r2(Math.max(0, Number($wire.discount) || 0)), base = this.subtotal();
            return this.r2(Math.min(base, $wire.discountType === 'percent' ? base * Math.min(100, v) / 100 : v));
        },
        total() { return this.r2(Math.max(0, this.subtotal() - this.discountAmt()) + this.shipping()) },
        tipAmt() { return this.r2(Math.max(0, Number($wire.tip) || 0)) },
        due() { return this.r2(this.total() + this.tipAmt()) },
        // Propina sugerida en pesos cerrados: los centavos estorban al dar cambio.
        tipFor(pct) { return Math.round((this.total() - this.shipping()) * pct / 100) },
        setTip(pct) { $wire.tip = pct === null ? null : String(this.tipFor(pct)) },
        // Lo que se paga en efectivo: todo, o en pago mixto solo la parte en efectivo.
        cashDue() {
            if ($wire.method === 'cash') return this.due();
            if ($wire.method === 'mixed') return this.r2(Math.min(this.due(), Math.max(0, Number($wire.cashPart) || 0)));
            return 0;
        },
        change() {
            const r = $wire.received;
            if (! this.cashDue() || r === null || r === '' || isNaN(r)) return null;
            return this.r2(Number(r) - this.cashDue());
        },
        // Con cuánto suelen pagar: el total redondeado hacia arriba con cada billete ($139 se paga con $140, $150 o $200)
        // y siempre el de $500, el billete grande con el que más pagan. Sin billetes de $1,000: nadie llega con uno a una taquería.
        quick() {
            const due = this.cashDue();
            const near = [20, 50, 100, 200, 500].map(b => Math.ceil(due / b) * b).filter(a => a > due);
            const amounts = [...new Set(near)].sort((a, b) => a - b).slice(0, 3);
            if (due < 500 && ! amounts.includes(500)) amounts.push(500);
            return amounts;
        },
        rest() { return this.r2(Math.max(0, this.due() - (Number($wire.cashPart) || 0))) },
        // Editor de indicaciones de un renglón
        edit(i) {
            const l = this.list()[i];
            const picked = {};
            (this.catalog[l.product_id]?.options ?? []).forEach(g => { const c = g.choices.find(c => (l.choices ?? []).some(x => x == c.id)); if (c) picked[g.id] = c.id });
            this.editor = { index: i, isNew: false, max: l.qty, count: l.qty, product_id: l.product_id, without: [...(l.without ?? [])], extras: [...(l.extras ?? [])], choices: picked, notes: l.notes ?? '', courtesy: !! l.courtesy, error: '' };
        },
        editNew(pid) {
            this.editor = { index: null, isNew: true, max: 99, count: 1, product_id: pid, without: [], extras: [], choices: {}, notes: '', courtesy: false, error: '' };
        },
        pick(group, choiceId) {
            const current = this.editor.choices[group.id];
            this.editor.choices = { ...this.editor.choices, [group.id]: current == choiceId && ! group.required ? null : choiceId };
            this.editor.error = '';
        },
        toggle(key, id) {
            const arr = this.editor[key];
            const i = arr.findIndex(x => x == id);
            i === -1 ? arr.push(id) : arr.splice(i, 1);
        },
        saveEdit() {
            const e = this.editor;
            const missing = (this.catalog[e.product_id]?.options ?? []).filter(g => g.required && g.choices.length > 1 && ! e.choices[g.id]);
            if (missing.length) return e.error = 'Elige ' + missing.map(g => g.name.toLowerCase()).join(' y ') + '.';
            const lines = this.list().map(l => ({ ...l }));
            const edited = { product_id: e.product_id, qty: e.count, without: e.without, extras: e.extras, choices: Object.values(e.choices).filter(Boolean), notes: e.notes.trim(), courtesy: !! e.courtesy };
            let at = lines.length;
            if (! e.isNew) {
                const original = lines[e.index];
                if (e.count < original.qty) { original.qty -= e.count; } else { lines.splice(e.index, 1); }
                at = Math.min(e.index + 1, lines.length);
            }
            const twin = lines.findIndex(l => this.same(l, edited));
            twin === -1 ? lines.splice(at, 0, edited) : lines[twin].qty += edited.qty;
            $wire.lines = lines;
            this.saveDraft();
            const pid = e.product_id;
            this.editor = null;
            if (e.isNew) { navigator.vibrate?.(8); this.$nextTick(() => bump(document.getElementById('q-' + pid))) }
        },
        // Limpiar deja Vender como recién abierto: sin productos, nombre, servicio, descuento, propina ni forma de pago.
        // Si se está editando un pedido guardado, solo se quitan sus productos (para salir de la edición está Salir).
        clear() {
            const editing = !! $wire.orderId;
            $wire.lines = []; $wire.received = null; $wire.reference = null;
            this.emptied(); this.clearDraft(); this.unstashDraft();
            if (! editing) { this.later = false; this.discOpen = false; this.error = ''; $wire.cancelEdit() }
        },
        async confirmClear() {
            if (! this.count()) return;
            const editing = !! $wire.orderId, n = this.count();
            const message = editing
                ? 'Se quitarán ' + n + (n === 1 ? ' producto' : ' productos') + ' de este pedido.'
                : 'Se borra todo para empezar de nuevo: ' + n + (n === 1 ? ' producto' : ' productos') + ', nombre, descuento y forma de pago.';
            if (await this.$store.confirm.ask({ title: '¿Limpiar el pedido?', message, action: 'Limpiar' })) this.clear();
        },
        // Lleva la vista al campo con el problema: el aviso sale en el pie y el campo puede estar fuera de vista.
        focusField(id) {
            this.$nextTick(() => {
                const el = document.getElementById(id);
                el?.focus({ preventScroll: true });
                el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        },
        async submit(action) {
            if (this.busy) return;
            this.error = '';
            if (this.later && ! $wire.pickupTime) {
                this.error = 'Elige la hora en que lo recogen.';
                return this.focusField('pickup');
            }
            this.busy = true;
            const editing = !! $wire.orderId;
            try {
                const r = action === 'charge' ? await $wire.charge(this.due()) : await $wire[action]();
                if (r?.ok) {
                    this.sheet = false;
                    if (editing) {
                        this.restoreDraft('Volvió el pedido que estabas armando.');
                    } else {
                        this.clearDraft();
                        this.unstashDraft();
                    }
                    this.refresh();
                } else if (r?.closed) {
                    this.sheet = false;
                    if (editing) this.restoreDraft('Volvió el pedido que estabas armando.');
                } else {
                    const refreshed = r?.prices_changed ? await this.refresh() : true;
                    this.error = refreshed ? (r?.error ?? 'No se pudo guardar.') : 'El total cambió. Recarga la página, revisa el nuevo importe y vuelve a confirmar.';
                    if (r?.field) this.focusField(r.field);
                }
            } catch (e) {
                this.error = 'Sin conexión. Revisa tu internet e intenta de nuevo.';
            } finally { this.busy = false }
        },
        // Pedido guardado que se está editando: si se sale con cambios, se pregunta antes de perderlos.
        editSnapshot: null,
        snapshot() {
            const ids = (x) => [...(x ?? [])].map(Number).sort();
            return JSON.stringify([
                this.list().map(l => [Number(l.product_id), l.qty, ids(l.without), ids(l.extras), ids(l.choices), !! l.courtesy, (l.notes ?? '').trim()]),
                this.draftFields.map(k => $wire[k] ?? null),
            ]);
        },
        async leaveEdit() {
            if (this.snapshot() !== this.editSnapshot && ! await this.$store.confirm.ask({ title: '¿Salir sin guardar?', message: 'Se perderán los cambios a este pedido.', action: 'Salir sin guardar', cancel: 'Seguir editando' })) return;
            await $wire.cancelEdit();
            this.restoreDraft('Volvió el pedido que estabas armando.');
        },
    }">
    <x-page-header :title="$this->editing ? 'Pedido #'.$this->editing->daily_number : 'Vender'" tour="vender">
        <x-slot:actions>
            @if ($this->editing)
                <button type="button" @click="leaveEdit()" class="btn">Salir</button>
            @else
                <button type="button" @click="stock = ! stock" data-tour="agotados" class="btn" :class="stock && '!bg-ink !text-bg !border-ink'">
                    <x-icon name="eye-off" class="size-4" /> <span x-show="! stock">Agotados</span><span x-show="stock" x-cloak>Listo</span>
                </button>
            @endif
        </x-slot:actions>
        <p x-show="stock" x-cloak class="mb-3 rounded-xl bg-ink px-3 py-2.5 text-sm text-bg">
            Toca los productos que se acabaron. Quedan marcados solo por hoy; mañana vuelven a estar disponibles.
        </p>
        @if ($this->editing)
            <p class="mb-3 rounded-xl bg-surface/80 px-3 py-2 text-sm">
                Editando <span class="font-semibold">{{ $this->editing->label }}</span>, por cobrar. Agrega o quita productos y guarda, o cóbralo.
            </p>
        @endif
        @if ($this->categories->count() > 1)
            <div data-tour="categorias" class="scroll-fade -mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:px-0">
                <button type="button" class="chip" :aria-pressed="category === ''" @click="category = ''">Todo</button>
                @foreach ($this->categories as $cat)
                    <button type="button" class="chip" :aria-pressed="category === @js($cat)" @click="category = @js($cat)">{{ $cat }}</button>
                @endforeach
            </div>
        @endif
    </x-page-header>

    {{--
        El panel del pedido va al lado desde 1024 px. En tablet vertical (768) con la barra lateral no queda espacio:
        ahí se usa la misma barra "Ver pedido" del celular y las tarjetas van de tres en tres.
    --}}
    <div class="md:pb-20 lg:grid lg:grid-cols-[1fr_20rem] lg:items-start lg:gap-6 lg:pb-0 xl:grid-cols-[1fr_23rem]">
        <div>
            @if ($this->products->isEmpty())
                <x-empty icon="tag" title="Aún no hay productos">
                    Da de alta lo que vendes, con su precio, para poder cobrar.
                    <x-slot:action>
                        <a href="{{ route('productos') }}" wire:navigate class="btn-primary">Agregar productos</a>
                    </x-slot:action>
                </x-empty>
            @else
                {{-- Tarjetas blancas y tranquilas; las que ya llevan piezas se pintan de amarillo. --}}
                <div class="grid grid-cols-2 gap-2.5 sm:gap-3 md:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->products as $product)
                        <div wire:key="p-{{ $product->id }}" @if ($loop->first) data-tour="producto" @endif
                             x-show="category === '' || category === @js($product->category)"
                             {{--
                                 El estado va en atributos data-* y el CSS lo pinta: alternar juegos de clases con Alpine
                                 dejaba tarjetas con piezas sin amarillo en algunos Android. Solo se anima el tamaño.
                             --}}
                             class="relative min-h-[8.25rem] rounded-2xl border-2 border-line bg-surface motion-safe:transition-transform motion-safe:duration-150 motion-safe:has-[.add:active]:scale-[0.97] data-selected:border-limon data-selected:bg-limon data-selected:text-on-limon data-sold-out:opacity-55 data-sold-out:grayscale data-stock:ring-2 data-stock:ring-ink/25 data-stock:ring-offset-2 data-stock:ring-offset-bg"
                             :data-selected="qtyOf({{ $product->id }}) > 0"
                             :data-sold-out="!! catalog[{{ $product->id }}]?.soldOut"
                             :data-stock="stock">
                            <button type="button" @click="tap({{ $product->id }})" class="add absolute inset-0 w-full touch-manipulation rounded-2xl" aria-label="Agregar {{ $product->name }}"></button>
                            <span x-show="catalog[{{ $product->id }}]?.soldOut" x-cloak class="pointer-events-none absolute right-2.5 bottom-3 z-10 rounded-md bg-ink px-2 py-0.5 text-xs font-bold text-bg">Agotado</span>
                            <div class="pointer-events-none relative flex h-full min-h-[8.25rem] flex-col justify-between gap-3 p-3.5">
                                {{-- Una palabra larga (Hamburguesa) no se puede partir bien: se achica la letra para que quepa entera. --}}
                                @php $longestWord = max(array_map('mb_strlen', explode(' ', $product->name))); @endphp
                                <p class="font-display {{ $longestWord > 9 ? 'text-[1.1rem]' : (mb_strlen($product->name) > 12 ? 'text-[1.2rem]' : 'text-[1.35rem]') }} leading-[1.05] [overflow-wrap:break-word]" lang="es">{{ $product->name }}</p>
                                <span class="num text-[1.75rem] leading-none">{{ money($product->price) }}</span>
                            </div>
                            <template x-if="qtyOf({{ $product->id }}) > 0">
                                <div>
                                    <span id="q-{{ $product->id }}" x-text="qtyOf({{ $product->id }})"
                                          class="num pointer-events-none absolute -top-2 -right-2 z-10 grid size-9 place-items-center rounded-full bg-chile text-xl text-white shadow-sm ring-3 ring-bg"></span>
                                    <button type="button" @click="removeOne({{ $product->id }})" class="btn-icon absolute right-2 bottom-2 touch-manipulation" aria-label="Quitar uno de {{ $product->name }}">
                                        <x-icon name="minus" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Pedido (escritorio). Lo maneja el navegador. --}}
        <aside class="sticky top-6 hidden lg:block" aria-label="Pedido actual" wire:ignore data-tour="pedido-panel">
            <div class="card space-y-3 !p-5">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Pedido</h2>
                    <button type="button" x-show="count() > 0" x-cloak @click="confirmClear()" class="btn-quiet -mr-2"><x-icon name="trash" class="size-4" /> Limpiar</button>
                </div>
                <div x-show="count() === 0" class="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted">
                    <x-icon name="ticket" class="size-7 opacity-60" />
                    Toca un producto para agregarlo.
                </div>
                <div x-show="count() > 0" x-cloak>
                    @include('partials.order-lines')
                </div>
                <div class="flex items-end justify-between border-t border-line pt-4">
                    <span class="text-sm font-semibold text-muted">Total</span>
                    <span class="num text-[2.75rem] leading-none font-bold" x-text="fmt(total())"></span>
                </div>
                <button type="button" @click="openSheet()" class="btn-primary btn-lg w-full" :disabled="count() === 0">Continuar</button>
            </div>
        </aside>
    </div>

    {{-- Barra de pedido (celular) --}}
    <div wire:ignore>
        <div x-show="count() > 0" x-cloak
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-4 opacity-0"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="translate-y-4 opacity-0"
             class="fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 flex gap-2 md:right-5 md:bottom-5 md:left-[calc(15.5rem+1.25rem)] lg:hidden">
            {{-- Limpiar a la mano, sin abrir el pedido: pide confirmación antes de borrar. --}}
            <button type="button" @click="confirmClear()" aria-label="Limpiar pedido"
                    class="grid size-[4.25rem] shrink-0 touch-manipulation place-items-center rounded-2xl border border-line bg-surface text-chile shadow-lg motion-safe:transition motion-safe:active:scale-[0.98]">
                <x-icon name="trash" class="size-6" />
            </button>
            <button type="button" @click="openSheet()"
                    class="flex min-w-0 flex-1 touch-manipulation items-center gap-3 rounded-2xl bg-chile py-3 pr-4 pl-3 text-left text-white shadow-[0_10px_30px_-8px_rgb(224_36_36/0.6)] motion-safe:transition motion-safe:active:scale-[0.98]">
                <span class="num grid size-11 place-items-center rounded-xl bg-white/18 text-2xl font-bold" x-text="count()"></span>
                <span class="flex-1 text-base font-semibold">Ver pedido</span>
                <span class="num text-[2rem] leading-none font-bold" x-text="fmt(total())"></span>
            </button>
        </div>
    </div>

    {{-- Venta registrada (la pinta el servidor después de cobrar) --}}
    @if ($lastOrderId)
        {{--
            Sale unos segundos para deshacer un error al cobrar; después se corrige desde Pedidos.
            Se quita antes si empieza otro pedido: tapaba la barra del pedido nuevo.
        --}}
        {{-- Tocarlo fuera de Deshacer lo cierra, por si tapa algo. --}}
        <div wire:key="done-{{ $lastOrderId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-effect="if (count() > 0) show = false" x-show="show" x-cloak
             @click="if (! $event.target.closest('[data-undo]')) show = false"
             x-transition:leave="transition duration-200" x-transition:leave-end="opacity-0 translate-y-3"
             class="sheet-in fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-3 rounded-2xl bg-ink py-3 pr-3 pl-4 text-bg shadow-xl md:inset-x-auto md:right-6 md:bottom-6 md:w-96"
             role="status">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-salsa text-on-salsa"><x-icon name="check" class="size-5" /></span>
            <span class="min-w-0 flex-1 leading-tight">
                <span class="block truncate text-sm opacity-75">Cobrado {{ $lastTitle }}</span>
                <span class="num block text-2xl font-bold">{{ money($lastTotal) }}</span>
                @if ($lastChange > 0)
                    <span class="block text-sm font-semibold text-salsa-soft">Cambio <span class="num text-base">{{ money($lastChange) }}</span></span>
                @endif
            </span>
            <button type="button" wire:click="undo" data-undo class="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-bg/12 px-3.5 text-sm font-semibold hover:bg-bg/20">
                <x-icon name="undo" class="size-4" /> Deshacer
            </button>
            <button type="button" class="-ml-1 grid size-9 shrink-0 place-items-center rounded-lg opacity-60 hover:opacity-100" aria-label="Cerrar aviso">
                <x-icon name="x" class="size-4" />
            </button>
        </div>
    @endif

    {{-- Pedido: nombre, renglones y forma de pago --}}
    <div wire:ignore>
        <x-sheet title="Pedido" open="sheet">
            <div class="space-y-5" @input="error = ''" @input.debounce.400ms="saveDraft()" @click="error = ''; $nextTick(() => saveDraft())">
                <div data-tour="para-quien">
                    <label class="label" for="label">¿Para quién? <span class="font-normal text-muted">(opcional al cobrar)</span></label>
                    <input id="label" type="text" x-model="$wire.label" maxlength="60" class="input" placeholder="Ej. camioneta negra, Juan, mesa 2" autocomplete="off">
                </div>

                <div data-tour="servicio">
                    <p class="label">Servicio</p>
                    <div class="segmented" role="group" aria-label="Servicio">
                        <button type="button" @click="$wire.service = 'togo'" :aria-pressed="$wire.service === 'togo'">Para llevar</button>
                        <button type="button" @click="$wire.service = 'here'" :aria-pressed="$wire.service === 'here'">Aquí</button>
                        <button type="button" @click="$wire.service = 'delivery'; $nextTick(() => $refs.address?.focus())" :aria-pressed="$wire.service === 'delivery'">A domicilio</button>
                    </div>
                </div>
                <div x-show="$wire.service === 'delivery'" x-cloak class="space-y-3 rounded-2xl bg-naranja-soft/70 p-4">
                    <div>
                        <label class="label" for="address">Dirección</label>
                        <input id="address" x-ref="address" type="text" x-model="$wire.address" maxlength="255" class="input" placeholder="Calle, número y colonia" autocomplete="off">
                    </div>
                    <div class="grid grid-cols-[1fr_7.5rem] gap-3">
                        <div>
                            <label class="label" for="phone">Teléfono</label>
                            <input id="phone" type="tel" inputmode="tel" x-model="$wire.phone" maxlength="30" class="input" placeholder="868 123 4567" autocomplete="off">
                        </div>
                        <div>
                            <label class="label" for="delivery-fee">Envío</label>
                            <x-money-input id="delivery-fee" x-model="$wire.deliveryFee" x-bind:placeholder="String(deliveryDefault)" />
                        </div>
                    </div>
                    <div>
                        <label class="label" for="address-notes">Referencias <span class="font-normal text-muted">(opcional)</span></label>
                        <input id="address-notes" type="text" x-model="$wire.addressNotes" maxlength="160" class="input" placeholder="Ej. casa azul, portón negro">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3" data-tour="cuando">
                    <div class="col-span-2">
                        <p class="label">¿Cuándo?</p>
                        <div class="segmented" role="group" aria-label="Cuándo">
                            <button type="button" @click="later = false; $wire.pickupTime = null" :aria-pressed="! later">Ahora</button>
                            <button type="button" @click="later = true; $nextTick(() => $refs.pickup?.focus())" :aria-pressed="later">Más tarde</button>
                        </div>
                    </div>
                </div>
                <div x-show="later" x-cloak>
                    <label class="label" for="pickup" x-text="{ delivery: 'Hora de entrega', here: 'Hora a la que llegan', togo: 'Hora en que lo recogen' }[$wire.service] ?? 'Hora en que lo recogen'">Hora a la que llegan</label>
                    <input id="pickup" x-ref="pickup" type="time" x-model="$wire.pickupTime" class="input" step="300">
                </div>

                <div data-tour="renglones">
                    <div class="mb-1 flex items-baseline justify-between">
                        <p class="label !mb-0">Productos</p>
                        <p class="text-sm text-muted">Toca uno para indicaciones</p>
                    </div>
                    @include('partials.order-lines')
                    {{-- Limpiar también vive aquí por si el cuadro ya está abierto; en la barra de abajo está sin abrirlo. --}}
                    <button type="button" @click="confirmClear()" class="btn-quiet-danger -ml-2.5 mt-1 lg:hidden"><x-icon name="trash" class="size-4" /> Limpiar pedido</button>
                </div>

                {{-- Desglose --}}
                <div class="space-y-1.5 rounded-2xl bg-sunken px-4 py-3 text-[15px]">
                    <div class="flex justify-between" x-show="packaging() > 0 || discountAmt() > 0 || shipping() > 0" x-cloak>
                        <span class="text-muted" x-text="count() === 1 ? '1 producto' : count() + ' productos'"></span>
                        <span class="num text-lg font-semibold" x-text="fmt(subtotal() - (count() ? packaging() : 0))"></span>
                    </div>
                    <div class="flex justify-between" x-show="packaging() > 0" x-cloak>
                        <span class="text-muted">Empaque para llevar</span>
                        <span class="num text-lg font-semibold" x-text="fmt(packaging())"></span>
                    </div>
                    <div class="flex justify-between text-salsa" x-show="discountAmt() > 0" x-cloak>
                        <span>Descuento</span>
                        <span class="num text-lg font-semibold" x-text="'−' + fmt(discountAmt())"></span>
                    </div>
                    <div class="flex justify-between" x-show="shipping() > 0" x-cloak>
                        <span class="text-muted">Envío a domicilio</span>
                        <span class="num text-lg font-semibold" x-text="fmt(shipping())"></span>
                    </div>
                    <div class="flex items-end justify-between pt-1" :class="(packaging() > 0 || discountAmt() > 0 || shipping() > 0) && 'border-t border-line'">
                        <span class="pb-1 text-sm font-semibold text-muted" x-text="(packaging() > 0 || discountAmt() > 0 || shipping() > 0) ? 'Total' : (count() === 1 ? '1 producto' : count() + ' productos')"></span>
                        <span class="num text-[2.75rem] leading-none font-bold" x-text="fmt(total())"></span>
                    </div>
                </div>

                {{-- Descuento --}}
                <div>
                    <button type="button" x-show="! discOpen" @click="discOpen = true; $nextTick(() => $refs.disc.focus())" class="btn-quiet -ml-2.5"><x-icon name="plus" class="size-4" /> Descuento</button>
                    <div x-show="discOpen" x-cloak>
                        <p class="label">Descuento</p>
                        <p class="mb-2 text-sm text-muted">Máximo aplicado: <span x-text="$wire.discountType === 'percent' ? '100%' : fmt(subtotal())"></span>.</p>
                        <div class="flex gap-2">
                            <input x-ref="disc" id="discount" type="number" inputmode="decimal" min="0" step="0.01" :max="$wire.discountType === 'percent' ? 100 : subtotal()" @change="$wire.discount = $el.value === '' ? null : String(r2(Math.max(0, Number($el.value) || 0)))" x-model="$wire.discount" class="input num flex-1 text-xl font-semibold" placeholder="0">
                            <div class="segmented w-28 shrink-0" role="group" aria-label="Tipo de descuento">
                                <button type="button" @click="$wire.discountType = 'amount'" :aria-pressed="$wire.discountType === 'amount'">$</button>
                                <button type="button" @click="$wire.discountType = 'percent'" :aria-pressed="$wire.discountType === 'percent'">%</button>
                            </div>
                            <button type="button" @click="$wire.discount = null; discOpen = false" class="btn-quiet size-12 justify-center !px-0" aria-label="Quitar descuento"><x-icon name="x" class="size-4" /></button>
                        </div>
                    </div>
                </div>

                <p x-show="subtotal() > 0 && discountAmt() >= subtotal()" x-cloak role="status" class="rounded-xl bg-naranja-soft p-3 text-sm font-semibold">El descuento cubre todo el pedido. Solo se cobrará el envío y la propina, si hay.</p>

                <div data-tour="pago">
                    <p class="label">Forma de pago</p>
                    <div class="segmented" role="group" aria-label="Forma de pago">
                        <button type="button" @click="$wire.method = 'cash'" :aria-pressed="$wire.method === 'cash'">Efectivo</button>
                        <button type="button" @click="$wire.method = 'transfer'" :aria-pressed="$wire.method === 'transfer'">Transf.</button>
                        <button type="button" @click="$wire.method = 'card'" :aria-pressed="$wire.method === 'card'">Tarjeta</button>
                        <button type="button" @click="$wire.method = 'mixed'" :aria-pressed="$wire.method === 'mixed'">Mixto</button>
                    </div>
                </div>

                {{-- Propina --}}
                <div data-tour="propina">
                    <p class="label">Propina <span class="font-normal text-muted">(no cuenta como venta)</span></p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="chip" :aria-pressed="tipAmt() === 0" @click="setTip(null)">Sin propina</button>
                        <button type="button" class="chip" :aria-pressed="tipAmt() > 0 && tipAmt() === tipFor(10)" @click="setTip(10)">10%</button>
                        <button type="button" class="chip" :aria-pressed="tipAmt() > 0 && tipAmt() === tipFor(15)" @click="setTip(15)">15%</button>
                        <div class="relative w-28">
                            <span class="num pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 font-semibold text-muted">$</span>
                            <input id="tip" type="number" inputmode="decimal" min="0" step="0.5" x-model="$wire.tip" class="input num !min-h-9 !py-1 !pl-7 text-base font-semibold" placeholder="Otra" aria-label="Otra propina">
                        </div>
                    </div>
                </div>

                <div x-show="$wire.method === 'mixed'" x-cloak class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="cash-part">En efectivo</label>
                            <x-money-input id="cash-part" x-model="$wire.cashPart" placeholder="0" />
                        </div>
                        <div>
                            <p class="label">El resto con</p>
                            <div class="segmented" role="group" aria-label="Resto con">
                                <button type="button" @click="$wire.otherMethod = 'transfer'" :aria-pressed="$wire.otherMethod === 'transfer'">Transf.</button>
                                <button type="button" @click="$wire.otherMethod = 'card'" :aria-pressed="$wire.otherMethod === 'card'">Tarjeta</button>
                            </div>
                        </div>
                    </div>
                    <p x-show="Number($wire.cashPart) >= due()" x-cloak class="text-sm font-semibold text-chile">El efectivo tiene que ser menos que el total. Si paga todo en efectivo, elige Efectivo.</p>
                    <p class="flex justify-between rounded-xl bg-sunken px-4 py-2.5 text-[15px]">
                        <span class="text-muted" x-text="($wire.otherMethod === 'card' ? 'Con tarjeta' : 'Por transferencia')"></span>
                        <span class="num text-xl font-bold" x-text="fmt(rest())"></span>
                    </p>
                </div>

                {{-- Con cuánto pagan en efectivo y el cambio; en pago mixto, contra la parte en efectivo. --}}
                <div x-show="$wire.method === 'cash' || ($wire.method === 'mixed' && cashDue() > 0 && cashDue() < due())" class="space-y-2.5">
                    <p class="label !mb-0" x-text="$wire.method === 'mixed' ? 'Recibido en efectivo' : 'Recibido'">Recibido</p>
                    {{-- Cinco columnas iguales: Exacto y hasta cuatro montos caben en una fila aun en celulares chicos. --}}
                    <div class="grid grid-cols-[1.25fr_repeat(4,1fr)] gap-1.5">
                        <button type="button" class="chip justify-center !px-0" :aria-pressed="$wire.received !== null && Number($wire.received) === cashDue()" @click="$wire.received = String(cashDue())">Exacto</button>
                        <template x-for="amount in quick()" :key="amount">
                            <button type="button" class="chip num justify-center !px-0 !text-base" :aria-pressed="Number($wire.received) === amount" @click="$wire.received = String(amount)" x-text="'$' + amount"></button>
                        </template>
                    </div>
                    <x-money-input id="received" x-model="$wire.received" placeholder="Otro monto" aria-label="Monto recibido" />
                    {{-- Al escribir lo recibido, el cambio se deja ver: abajo lo tapaba el pie del cuadro. --}}
                    <div x-show="change() !== null" x-cloak x-effect="if (change() !== null) $nextTick(() => $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))" class="flex items-center justify-between rounded-xl px-4 py-3"
                         :class="change() >= 0 ? 'bg-salsa-soft text-salsa' : 'bg-chile-soft text-chile'">
                        <span class="text-sm font-bold" x-text="change() >= 0 ? 'Cambio' : 'Faltan'"></span>
                        <span class="num text-[2.5rem] leading-none font-bold" x-text="fmt(Math.abs(change() ?? 0))"></span>
                    </div>
                </div>

                <div x-show="$wire.method !== 'cash'" x-cloak>
                    <label class="label" for="reference">Referencia <span class="font-normal text-muted">(opcional)</span></label>
                    <input id="reference" type="text" x-model="$wire.reference" maxlength="255" class="input" placeholder="Folio o últimos dígitos">
                </div>
            </div>

            <x-slot:footer>
                {{-- Va en el pie para que se vea aunque el cuadro esté desplazado. --}}
                <p x-show="error" x-cloak role="alert" class="mb-2 flex items-start gap-2 rounded-xl bg-chile-soft px-3 py-2.5 text-sm font-semibold text-chile">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0" /> <span x-text="error"></span>
                </p>
                <div class="grid grid-cols-[auto_1fr] gap-2" data-tour="cobrar">
                    <button type="button" @click="submit('saveOpen')" class="btn btn-lg !px-4" :disabled="busy || count() === 0">Guardar sin cobrar</button>
                    <button type="button" @click="submit('charge')" class="btn-primary btn-lg touch-manipulation"
                            :disabled="busy || count() === 0 || (change() !== null && change() < 0) || ($wire.method === 'mixed' && ! (Number($wire.cashPart) > 0 && Number($wire.cashPart) < due()))">
                        <span x-show="!busy">Cobrar <span class="num text-xl" x-text="fmt(due())"></span></span>
                        <span x-show="busy" x-cloak>Guardando...</span>
                    </button>
                </div>
            </x-slot:footer>
        </x-sheet>

        {{-- Indicaciones de un renglón --}}
        <x-sheet title="Indicaciones" open="editor" dismiss="editor = null" x-text="editor ? catalog[editor.product_id]?.name : 'Indicaciones'">
            <template x-if="editor">
                <div class="space-y-5">
                    <div x-show="editor.max > 1" class="flex items-center justify-between gap-3 rounded-2xl bg-sunken px-4 py-3">
                        <span class="text-[15px] font-semibold" x-text="editor.isNew ? 'Cantidad' : 'Aplicar a'"></span>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn-icon" @click="editor.count = Math.max(1, editor.count - 1)" :disabled="editor.count <= 1" aria-label="Menos"><x-icon name="minus" /></button>
                            <span class="num w-14 text-center text-2xl font-bold"><span x-text="editor.count"></span><span class="text-base text-muted" x-show="! editor.isNew" x-text="' de ' + editor.max"></span></span>
                            <button type="button" class="btn-icon" @click="editor.count = Math.min(editor.max, editor.count + 1)" :disabled="editor.count >= editor.max" aria-label="Más"><x-icon name="plus" /></button>
                        </div>
                    </div>

                    {{-- Al agregar un producto solo se pide lo obligatorio; los Sin, extras y notas se ponen tocando su renglón en el pedido. --}}
                    <template x-for="g in (catalog[editor.product_id]?.options ?? []).filter(g => ! (g.required && g.choices.length === 1) && (! editor.isNew || g.required))" :key="g.id">
                        <div>
                            <p class="label"><span x-text="g.name"></span> <span class="font-normal text-muted" x-text="g.required ? '(elige uno)' : '(opcional)'"></span></p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="c in g.choices" :key="c.id">
                                    <button type="button" @click="pick(g, c.id)" class="chip gap-1.5"
                                            :class="editor.choices[g.id] == c.id && '!border-ink !bg-ink !text-bg'">
                                        <span x-text="c.name"></span>
                                        <span class="num opacity-80" x-show="c.price > 0" x-text="'+' + fmt(c.price)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div x-show="! editor.isNew && catalog[editor.product_id]?.without.length">
                        <p class="label">Sin</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="w in catalog[editor.product_id]?.without ?? []" :key="w.id">
                                <button type="button" @click="toggle('without', w.id)"
                                        class="chip"
                                        :class="editor.without.some(x => x == w.id) && '!border-chile !bg-chile !text-white'"
                                        x-text="'Sin ' + w.name.toLowerCase()"></button>
                            </template>
                        </div>
                    </div>

                    <div x-show="! editor.isNew && catalog[editor.product_id]?.extras.length">
                        <p class="label">Extras</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="x in catalog[editor.product_id]?.extras ?? []" :key="x.id">
                                <button type="button" @click="toggle('extras', x.id)"
                                        class="chip gap-1.5"
                                        :class="editor.extras.some(y => y == x.id) && '!border-salsa !bg-salsa !text-on-salsa'">
                                    <span x-text="x.name"></span>
                                    <span class="num opacity-80" x-show="x.price > 0" x-text="'+' + fmt(x.price)"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="! editor.isNew">
                        <label class="label" for="line-notes">Nota</label>
                        <input id="line-notes" type="text" x-model="editor.notes" maxlength="120" class="input" placeholder="Ej. bien dorada, salsa aparte" @keydown.enter.prevent="saveEdit()">
                    </div>

                    <label x-show="! editor.isNew" class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                        <span>
                            <span class="block font-semibold">Cortesía</span>
                            <span class="block text-sm text-muted">Va gratis. Su costo sí cuenta en la ganancia.</span>
                        </span>
                        <input type="checkbox" x-model="editor.courtesy" class="switch">
                    </label>

                    <p x-show="! editor.isNew && ! catalog[editor.product_id]?.without.length && ! catalog[editor.product_id]?.extras.length && ! catalog[editor.product_id]?.options.length" class="flex gap-2 text-sm text-muted">
                        <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                        <span>Si le agregas receta y extras a este producto en Productos, aquí aparecerán botones como "Sin cebolla".</span>
                    </p>
                </div>
            </template>

            <x-slot:footer>
                <p x-show="editor?.error" x-text="editor?.error" x-cloak role="alert" class="mb-2 flex items-center gap-2 rounded-xl bg-chile-soft px-3 py-2.5 text-sm font-semibold text-chile"></p>
                <div class="grid grid-cols-[auto_1fr] gap-2">
                    <button type="button" class="btn btn-lg !px-4" x-show="editor?.isNew" @click="editor = null">Cancelar</button>
                    <button type="button" class="btn btn-lg !px-4 text-chile" x-show="! editor?.isNew" @click="removeLine(editor.index); editor = null">
                        <x-icon name="trash" class="size-4" /> Quitar
                    </button>
                    <button type="button" class="btn-primary btn-lg" @click="saveEdit()" x-text="editor?.isNew ? 'Agregar al pedido' : 'Listo'">Listo</button>
                </div>
            </x-slot:footer>
        </x-sheet>
    </div>
</div>
