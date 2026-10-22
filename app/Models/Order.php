<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OnlineOrderUpdated;
use App\Events\OrdersChanged;
use App\Support\BusinessDay;
use App\Support\OnlineOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['source', 'customer_phone', 'telegram_chat_id', 'public_token', 'accepted_at', 'operation_token', 'replaces_order_id', 'user_id', 'daily_number', 'status', 'label', 'service', 'delivery_address', 'delivery_phone', 'delivery_notes', 'payment_method', 'payment_reference', 'total', 'packaging_fee', 'delivery_fee', 'discount', 'tip', 'notes', 'sold_at', 'pickup_at', 'paid_at', 'ready_at', 'dispatched_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'changed_at'])]
class Order extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Order $order) {
            $order->version = (int) $order->getOriginal('version') + 1;
        });

        // Pedidos, cocina y corte se enteran de cualquier cambio, sea cual sea el origen del pedido.
        // Al cliente solo se le avisa de lo que ve en su página (aceptado, listo, en camino, entregado, cancelado).
        static::updated(function (Order $order) {
            OnlineOrder::broadcast(new OrdersChanged($order));

            if ($order->source === 'online' && $order->public_token && $order->wasChanged(['status', 'accepted_at', 'ready_at', 'dispatched_at', 'delivered_at', 'cancelled_at'])) {
                OnlineOrder::broadcast(new OnlineOrderUpdated($order));
            }
        });

        // El sustituto de un pedido en línea vuelve a avisar al cliente por el mismo enlace.
        static::created(function (Order $order) {
            if ($order->source !== 'online' || $order->replaces_order_id) {
                OnlineOrder::broadcast(new OrdersChanged($order));
            }
            if ($order->source === 'online' && $order->replaces_order_id && $order->public_token) {
                OnlineOrder::broadcast(new OnlineOrderUpdated($order));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'version' => 'integer',
            'payment_method' => PaymentMethod::class,
            'total' => 'decimal:2',
            'packaging_fee' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'dispatched_at' => 'datetime',
            'discount' => 'decimal:2',
            'tip' => 'decimal:2',
            'sold_at' => 'datetime',
            'accepted_at' => 'datetime',
            'pickup_at' => 'datetime',
            'ready_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pedidos activos: todo lo que está por cobrar (aunque ya se haya entregado)
     * y lo pagado hoy que falta entregar. Un pedido para comer aquí ya cobrado sale en cuanto la cocina
     * lo marca Listo (no hay nada que entregar a mano); la cocina pide $includeReadyHere para seguir viéndolo.
     */
    public function scopePending(Builder $query, bool $includeReadyHere = false): void
    {
        $query->where(function (Builder $q) use ($includeReadyHere) {
            $q->where('status', OrderStatus::Open)
                ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Paid)->whereNull('delivered_at')->whereRaw('COALESCE(paid_at, sold_at) >= ?', [BusinessDay::startOfToday()])
                    ->when(! $includeReadyHere, fn (Builder $q) => $q->whereRaw("(COALESCE(service, '') <> 'here' OR ready_at IS NULL)")));
        });
    }

    /** Pedidos que el taquero ya vio: los de caja y los en línea que aceptó. Los que se pidieron y se rechazaron no cuentan. */
    public function scopeAccepted(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('source', '!=', 'online')->orWhereNotNull('accepted_at'));
    }

    /** Pedidos en línea que esperan a que el taquero los acepte o rechace. */
    public function scopeRequested(Builder $query): void
    {
        $query->where('status', OrderStatus::Requested);
    }

    /**
     * En qué va un pedido en línea, dicho para el cliente. "step" es el paso de la línea de tiempo (1 a 5); 0 si ya no avanza.
     *
     * @return array{key: string, step: int, title: string, text: string, tone: string}
     */
    public function customerStage(): array
    {
        $delivery = $this->isDelivery();
        $paid = $this->status === OrderStatus::Paid;

        if ($this->status === OrderStatus::Cancelled) {
            if ($this->cancel_reason === self::CUSTOMER_CANCELLED) {
                return ['key' => 'customer-cancelled', 'step' => 0, 'title' => 'Cancelaste tu pedido', 'text' => 'No hay nada que pagar. Cuando quieras, puedes hacer otro.', 'tone' => 'muted'];
            }
            if ($this->cancel_reason === self::CORRECTION) {
                return ['key' => 'correcting', 'step' => 3, 'title' => 'Estamos corrigiendo tu pedido', 'text' => 'La taquería está actualizando los detalles. Aquí mismo verás el pedido corregido.', 'tone' => 'wait'];
            }

            return $this->accepted_at
                ? ['key' => 'cancelled', 'step' => 0, 'title' => 'Tu pedido se canceló', 'text' => ($this->cancel_reason && $this->cancel_reason !== self::CORRECTION ? $this->cancel_reason.'. ' : '').'Si tienes dudas, comunícate con la taquería.', 'tone' => 'bad']
                : ['key' => 'rejected', 'step' => 0, 'title' => 'No pudimos aceptar tu pedido', 'text' => ($this->cancel_reason ?: 'La taquería no pudo atenderlo en este momento').'. Perdón por la molestia; puedes intentar de nuevo más tarde.', 'tone' => 'bad'];
        }

        return match (true) {
            $this->status === OrderStatus::Requested => ['key' => 'requested', 'step' => 1, 'title' => 'Recibimos tu pedido', 'text' => 'Esperando a que la taquería lo acepte. Deja esta página abierta: aquí mismo te avisamos.', 'tone' => 'wait'],
            $this->delivered_at !== null => ['key' => 'delivered', 'step' => 5, 'title' => 'Tu pedido fue entregado', 'text' => 'Gracias por tu pedido. ¡Buen provecho!', 'tone' => 'good'],
            $this->dispatched_at !== null => ['key' => 'on-the-way', 'step' => 4, 'title' => 'Tu pedido va en camino', 'text' => $paid ? 'Ya salió del puesto y quedó pagado.' : 'Ya salió del puesto. Ten tu dinero listo para pagar al recibirlo.', 'tone' => 'good'],
            $this->ready_at !== null => ['key' => 'ready', 'step' => 4, 'title' => $delivery ? 'Tu pedido está listo' : '¡Tu pedido está listo!', 'text' => $delivery ? 'En un momento sale con el repartidor.' : ($paid ? 'Pasa a recogerlo. Ya quedó pagado.' : 'Pasa a recogerlo y paga en el puesto.'), 'tone' => 'good'],
            default => ['key' => 'preparing', 'step' => 3, 'title' => 'Tu pedido fue aceptado', 'text' => $paid ? 'Ya quedó pagado. Lo estamos preparando.' : 'Lo estamos preparando.', 'tone' => 'good'],
        };
    }

    /** El enlace de seguimiento deja de funcionar un día después de que el pedido terminó (entregado o cancelado). */
    public function isTrackingExpired(): bool
    {
        $ended = $this->delivered_at ?? $this->cancelled_at;

        return $ended !== null && $ended->lt(now()->subDay());
    }

    public function isOnline(): bool
    {
        return $this->source === 'online';
    }

    /** Hora en que el cliente lo espera (la de recogida o la del pedido) dentro de [$from, $to). */
    public function scopeDueBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to = null): void
    {
        $query->when($from, fn (Builder $q) => $q->whereRaw('COALESCE(pickup_at, sold_at) >= ?', [$from]))
            ->when($to, fn (Builder $q) => $q->whereRaw('COALESCE(pickup_at, sold_at) < ?', [$to]));
    }

    /**
     * Órdenes que cuentan en el rango: las cobradas por la hora del cobro y las demás
     * por la hora del pedido. Así un pedido guardado anoche y cobrado hoy cuenta hoy,
     * igual que en el corte de caja.
     */
    public function scopeCountedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereRaw('COALESCE(paid_at, sold_at) BETWEEN ? AND ?', [$from, $to]);
    }

    public function scopePaidBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('status', OrderStatus::Paid)->countedBetween($from, $to);
    }

    /** Cuándo cuenta la orden: al cobrarse o, si aún no, al tomarse. */
    public function countedAt(): CarbonInterface
    {
        return $this->paid_at ?? $this->sold_at;
    }

    /** Hora en que el cliente lo espera: la de recogida o, si no hay, la del pedido. */
    public function dueAt(): CarbonInterface
    {
        return $this->pickup_at ?? $this->sold_at;
    }

    /** Lo que significa la hora de un pedido para más tarde: "Recoge 3:00 pm", "Llegan 3:00 pm" o "Entrega 3:00 pm". */
    public function scheduleVerb(): string
    {
        return match ($this->service) {
            'delivery' => 'Entrega',
            'here' => 'Llegan',
            default => 'Recoge',
        };
    }

    public function isToGo(): bool
    {
        return $this->service === 'togo';
    }

    public function isDelivery(): bool
    {
        return $this->service === 'delivery';
    }

    public function serviceLabel(): ?string
    {
        return match ($this->service) {
            'togo' => 'Para llevar',
            'here' => 'Aquí',
            'delivery' => 'A domicilio',
            default => null,
        };
    }

    /** Número para WhatsApp: 10 dígitos de México se completan con 52. */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($this->delivery_phone ?: $this->customer_phone));

        return match (true) {
            strlen($digits) === 10 => '52'.$digits,
            strlen($digits) > 10 => $digits,
            default => null,
        };
    }

    /**
     * Reemplaza los pagos de la venta (total más propina): todo con un método o, en mixto,
     * una parte en efectivo y el resto con transferencia o tarjeta.
     */
    public function recordPayments(string $method, ?float $cashPart = null, string $otherMethod = 'transfer', ?string $reference = null): void
    {
        DB::transaction(function () use ($method, $cashPart, $otherMethod, $reference) {
            $current = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== OrderStatus::Paid) {
                throw ValidationException::withMessages(['payment' => 'La venta ya no está pagada. Recarga antes de cambiar el pago.']);
            }

            $this->setRawAttributes($current->getAttributes(), true);
            $due = round((float) $this->total + (float) $this->tip, 2);
            if (! in_array($method, ['cash', 'transfer', 'card', 'mixed'], true)
                || ($method === 'mixed' && (! is_finite((float) $cashPart) || $cashPart <= 0 || $cashPart >= $due || ! in_array($otherMethod, ['transfer', 'card'], true)))) {
                throw ValidationException::withMessages(['payment' => 'Revisa la forma de pago y el monto en efectivo.']);
            }

            $reference = $method === 'cash' ? null : $reference;

            if ($method === 'mixed') {
                $cash = round(min($due, max(0, (float) $cashPart)), 2);
                $payments = array_values(array_filter([
                    ['method' => 'cash', 'amount' => $cash, 'reference' => null],
                    ['method' => $otherMethod, 'amount' => round($due - $cash, 2), 'reference' => $reference],
                ], fn ($p) => $p['amount'] > 0));
            } else {
                $payments = [['method' => $method, 'amount' => $due, 'reference' => $reference]];
            }

            $this->update(['payment_method' => $method, 'payment_reference' => $reference]);
            $this->payments()->delete();
            $this->payments()->createMany($payments);
            $this->unsetRelation('payments');
        });
    }

    /** Motivo de una venta que se canceló para corregirla y cobrarla de nuevo. */
    public const CORRECTION = 'correccion';

    /** Motivo de un pedido en línea que el propio cliente canceló antes de que lo aceptaran. */
    public const CUSTOMER_CANCELLED = 'cliente';

    /** Cancela dejando rastro de cuándo y por qué. */
    public function cancel(?string $reason = null): void
    {
        $this->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
    }

    /**
     * Venta cancelada con Deshacer que se volvió a cobrar con el mismo número.
     * Restaurarla duplicaría la venta.
     */
    public function wasCorrected(): bool
    {
        return $this->status === OrderStatus::Cancelled
            && (static::where('replaces_order_id', $this->id)->exists() || static::whereKeyNot($this->id)
                ->where('daily_number', $this->daily_number)
                ->where('status', '!=', OrderStatus::Cancelled)
                ->whereBetween('sold_at', [BusinessDay::start(BusinessDay::of($this->sold_at)), BusinessDay::end(BusinessDay::of($this->sold_at))])
                ->exists());
    }

    /** "3 Hamburguesa combo, 1 Torta": junta las partidas del mismo producto aunque lleven indicaciones distintas. */
    public function itemsSummary(): string
    {
        return $this->items->groupBy('product_name')
            ->map(fn ($items, $name) => $items->sum('quantity').' '.$name)
            ->join(', ');
    }

    /** Lo que muestra el contador de Pedidos: los activos de hoy en adelante, sin los sin cobrar de días anteriores. */
    public static function pendingCount(): int
    {
        return static::pending()->dueBetween(BusinessDay::startOfToday())->count() + static::requestedCount();
    }

    /** Pedidos en línea por aceptar. */
    public static function requestedCount(): int
    {
        return static::requested()->count();
    }

    /** Número consecutivo del día (#1, #2...) para llamar el pedido. */
    public static function nextDailyNumber(): int
    {
        return (int) static::where('sold_at', '>=', BusinessDay::startOfToday())->max('daily_number') + 1;
    }
}
