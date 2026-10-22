<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrdersChanged;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Los pasos de un pedido ya aceptado (listo, en camino, entregado, cobrado, cancelado), iguales para la
 * pantalla de Pedidos, la cocina y el bot de Telegram. Cada uno devuelve false si el pedido ya cambió.
 */
class OrderFlow
{
    public static function markReady(int $id, ?int $expectedVersion = null): bool
    {
        $changed = Order::whereKey($id)->whereIn('status', [OrderStatus::Open, OrderStatus::Paid])
            ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
            ->whereNull('ready_at')->whereNull('dispatched_at')->whereNull('delivered_at')
            ->update(['ready_at' => now(), 'version' => DB::raw('version + 1')]);

        return self::done($id, $changed);
    }

    public static function unready(int $id): bool
    {
        $changed = Order::whereKey($id)->whereIn('status', [OrderStatus::Open, OrderStatus::Paid])
            ->whereNotNull('ready_at')->whereNull('dispatched_at')->whereNull('delivered_at')
            ->update(['ready_at' => null, 'version' => DB::raw('version + 1')]);

        return self::done($id, $changed);
    }

    /** A domicilio: sale con el repartidor. Si ya salió, ya está preparado: deja la cocina. */
    public static function dispatch(int $id): bool
    {
        $order = Order::find($id);
        $changed = Order::whereKey($id)->whereIn('status', [OrderStatus::Open, OrderStatus::Paid])
            ->where('service', 'delivery')->whereNull('dispatched_at')->whereNull('delivered_at')->where('version', $order?->version)
            ->update(['dispatched_at' => now(), 'ready_at' => $order?->ready_at ?? now(), 'version' => DB::raw('version + 1')]);

        return self::done($id, $changed);
    }

    public static function deliver(int $id): bool
    {
        $changed = Order::whereKey($id)->whereIn('status', [OrderStatus::Open, OrderStatus::Paid])
            ->whereNull('delivered_at')
            ->where(fn ($q) => $q->whereNull('service')->orWhere('service', '!=', 'delivery')->orWhereNotNull('dispatched_at'))
            ->update(['delivered_at' => now(), 'version' => DB::raw('version + 1')]);

        return self::done($id, $changed);
    }

    public static function undeliver(int $id): bool
    {
        $changed = Order::whereKey($id)->whereIn('status', [OrderStatus::Open, OrderStatus::Paid])->whereNotNull('delivered_at')
            ->update(['delivered_at' => null, 'version' => DB::raw('version + 1')]);

        return self::done($id, $changed);
    }

    /** Cancela un pedido que todavía no se cobra. */
    public static function cancel(int $id): bool
    {
        $changed = Order::whereKey($id)->where('status', OrderStatus::Open)
            ->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => null, 'version' => DB::raw('version + 1')]);

        return self::done($id, $changed);
    }

    /** Cobra un pedido guardado, con todo el monto en un solo método (sin propina). */
    public static function charge(int $id, PaymentMethod $method): bool
    {
        if ($method === PaymentMethod::Mixed) {
            return false;
        }

        $changed = DB::transaction(function () use ($id, $method) {
            $updated = Order::whereKey($id)->where('status', OrderStatus::Open)
                ->update(['status' => OrderStatus::Paid, 'paid_at' => now(), 'payment_method' => $method->value, 'version' => DB::raw('version + 1')]);

            if ($updated) {
                Order::findOrFail($id)->recordPayments($method->value);
            }

            return $updated;
        });

        return self::done($id, $changed);
    }

    /** Avisa a quien sigue el pedido y deja al día los mensajes del taquero en Telegram. */
    protected static function done(int $id, int $changed): bool
    {
        if (! $changed) {
            return false;
        }

        $order = Order::with('items')->find($id);

        if ($order) {
            OnlineOrder::broadcast(new OrdersChanged($order));
        }

        if ($order?->isOnline()) {
            OnlineOrder::notifyCustomer($id);
        } elseif ($order) {
            TelegramNotifier::refreshStaff($order);
        }

        return true;
    }
}
