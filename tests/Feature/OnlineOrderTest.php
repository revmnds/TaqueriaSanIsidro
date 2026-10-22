<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Support\OnlineOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OnlineOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taco = Product::create(['name' => 'Taco de bistec', 'price' => 20]);
        // Jueves 8:30 pm: abierto.
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(20, 30));
        Setting::put('online_enabled', '1');
        RateLimiter::clear('online-order-ip|127.0.0.1');
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'token' => (string) Str::uuid(),
            'name' => 'Juan Pérez',
            'phone' => '(868) 123 4567',
            'service' => 'togo',
            'lines' => [['product_id' => $this->taco->id, 'qty' => 2]],
        ], $overrides);
    }

    private function place(array $overrides = [], string $ip = '127.0.0.1'): Order
    {
        return OnlineOrder::place($this->input($overrides), $ip);
    }

    private function assertRejected(array $overrides, string $field, ?string $contains = null): void
    {
        $before = Order::count();

        try {
            $this->place($overrides);
            $this->fail('Se esperaba un error en '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            if ($contains) {
                $this->assertStringContainsString($contains, $e->errors()[$field][0]);
            }
        }

        $this->assertSame($before, Order::count());
    }

    public function test_a_customer_order_waits_to_be_accepted_without_a_number_or_payment(): void
    {
        $order = $this->place();

        $this->assertSame(OrderStatus::Requested, $order->status);
        $this->assertSame('online', $order->source);
        $this->assertNull($order->daily_number);
        $this->assertNull($order->payment_method);
        $this->assertSame('Juan Pérez', $order->label);
        $this->assertSame('8681234567', $order->customer_phone);
        $this->assertTrue(Str::isUuid($order->public_token));
        $this->assertSame('40.00', $order->total);
        $this->assertSame(1, $order->items->count());
        $this->assertSame(1, Order::requestedCount());
    }

    public function test_it_does_not_enter_the_kitchen_sales_or_the_pending_list_until_accepted(): void
    {
        $order = $this->place();

        $this->assertSame(0, Order::pending()->count());
        $this->assertSame(0, Order::accepted()->where('id', $order->id)->count());

        OnlineOrder::accept($order);

        $this->assertSame(1, Order::pending()->count());
        $this->assertSame(1, Order::accepted()->where('id', $order->id)->count());
    }

    public function test_accepting_gives_the_next_daily_number_and_makes_it_a_normal_unpaid_order(): void
    {
        Order::create(['status' => OrderStatus::Paid, 'daily_number' => 7, 'sold_at' => now(), 'paid_at' => now(), 'total' => 10]);
        $order = $this->place();

        $this->assertTrue(OnlineOrder::accept($order));

        $order->refresh();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame(8, $order->daily_number);
        $this->assertNotNull($order->accepted_at);
        $this->assertFalse(OnlineOrder::accept($order), 'aceptar dos veces no hace nada');
    }

    public function test_rejecting_keeps_the_reason_and_the_order_never_counts_as_a_sale(): void
    {
        $order = $this->place();

        $this->assertTrue(OnlineOrder::reject($order, 'Se acabó algún producto'));

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Se acabó algún producto', $order->cancel_reason);
        $this->assertSame(0, Order::accepted()->where('id', $order->id)->count());
        $this->assertFalse(OnlineOrder::accept($order), 'un pedido rechazado ya no se acepta');
        $this->assertSame(0, Order::requestedCount());
    }

    public function test_the_customer_can_cancel_only_while_it_waits(): void
    {
        $waiting = $this->place();
        $this->assertTrue(OnlineOrder::cancelByCustomer($waiting));
        $this->assertSame(Order::CUSTOMER_CANCELLED, $waiting->fresh()->cancel_reason);

        $accepted = $this->place(['phone' => '8689999999']);
        OnlineOrder::accept($accepted);
        $this->assertFalse(OnlineOrder::cancelByCustomer($accepted));
        $this->assertSame(OrderStatus::Open, $accepted->fresh()->status);
    }

    public function test_it_cannot_be_ordered_when_online_orders_are_off_or_the_store_is_closed(): void
    {
        Setting::put('online_enabled', '0');
        $this->assertRejected([], 'store', 'no estamos recibiendo pedidos en línea');

        Setting::put('online_enabled', '1');
        Setting::put('store_mode', 'closed');
        Setting::put('store_message', 'Cerrado por mal clima');
        $this->assertRejected([], 'store', 'Cerrado por mal clima');

        Setting::put('store_mode', 'auto');
        Setting::put('store_message', null);
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
        $this->assertRejected([], 'store', 'cerrada');
    }

    public function test_delivery_is_blocked_with_the_message_when_the_taquero_turned_it_off(): void
    {
        $this->assertRejected(['service' => 'delivery', 'address' => 'Calle 1'], 'service', 'solo para pasar a recoger');

        Setting::put('online_delivery', '1');
        $this->assertRejected(['service' => 'delivery', 'address' => ''], 'address');

        $order = $this->place(['service' => 'delivery', 'address' => 'Calle Hidalgo 45', 'references' => 'Casa azul']);
        $this->assertSame('delivery', $order->service);
        $this->assertSame('Calle Hidalgo 45', $order->delivery_address);
        $this->assertSame('Casa azul', $order->delivery_notes);
        $this->assertSame('8681234567', $order->delivery_phone);
    }

    public function test_name_and_phone_are_required_and_checked(): void
    {
        $this->assertRejected(['name' => ''], 'name');
        $this->assertRejected(['name' => 'A'], 'name');
        $this->assertRejected(['name' => str_repeat('a', 61)], 'name');
        $this->assertRejected(['phone' => ''], 'phone');
        $this->assertRejected(['phone' => '12345'], 'phone');
        $this->assertRejected(['phone' => '1234567890'], 'phone');
        $this->assertRejected(['service' => 'here'], 'service');
        $this->assertRejected(['name' => ['x'], 'phone' => ['x']], 'name');
    }

    public function test_phones_are_normalized(): void
    {
        $this->assertSame('8681234567', OnlineOrder::normalizePhone('+52 868 123 4567'));
        $this->assertSame('8681234567', OnlineOrder::normalizePhone('52-868-123-4567'));
        $this->assertSame('8681234567', OnlineOrder::normalizePhone('(868) 123-4567'));
        $this->assertNull(OnlineOrder::normalizePhone('868123456'));
        $this->assertNull(OnlineOrder::normalizePhone('0681234567'));
    }

    public function test_prices_come_from_the_server_and_courtesies_cannot_be_asked_for(): void
    {
        $order = $this->place(['lines' => [['product_id' => $this->taco->id, 'qty' => 1, 'courtesy' => true, 'price' => 1, 'unit_price' => 0]]]);

        $this->assertSame('20.00', $order->total);
        $this->assertSame('20.00', $order->items->first()->unit_price);
        $this->assertFalse($order->items->first()->isCourtesy());
    }

    public function test_packaging_and_delivery_fees_are_added(): void
    {
        Setting::put('packaging_fee', '3');
        Setting::put('delivery_fee', '15');
        Setting::put('online_delivery', '1');

        $pickup = $this->place();
        $this->assertSame('43.00', $pickup->total);

        $delivery = $this->place(['service' => 'delivery', 'address' => 'Calle 1', 'phone' => '8689999999']);
        $this->assertSame('58.00', $delivery->total);
        $this->assertSame('15.00', $delivery->delivery_fee);
    }

    public function test_hidden_or_missing_products_and_empty_orders_are_rejected(): void
    {
        $this->assertRejected(['lines' => []], 'lines', 'Agrega');
        $this->assertRejected(['lines' => 'texto'], 'lines');
        $this->assertRejected(['lines' => [['product_id' => 999999, 'qty' => 1]]], 'lines');

        $this->taco->update(['active' => false]);
        $this->assertRejected([], 'lines', 'Taco de bistec');
    }

    public function test_quantities_are_capped_and_excess_lines_are_rejected(): void
    {
        $order = $this->place(['lines' => [['product_id' => $this->taco->id, 'qty' => 5000]]]);
        $this->assertSame(OnlineOrder::MAX_QUANTITY, $order->items->first()->quantity);

        RateLimiter::clear('online-order-ip|127.0.0.1');
        $many = array_fill(0, 100, ['product_id' => $this->taco->id, 'qty' => 1]);
        $this->assertRejected(['lines' => $many, 'phone' => '8689999999'], 'lines', 'hasta 30 renglones');
    }

    public function test_the_same_form_sent_twice_creates_a_single_order(): void
    {
        $input = $this->input();

        $first = OnlineOrder::place($input, '127.0.0.1');
        $second = OnlineOrder::place($input, '127.0.0.1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
    }

    public function test_too_many_orders_from_one_device_or_phone_are_blocked(): void
    {
        foreach (range(1, OnlineOrder::LIMIT_PER_PHONE) as $i) {
            $this->place();
        }
        $this->assertRejected([], 'store', 'varios pedidos seguidos');
    }

    public function test_too_many_orders_from_one_device_with_different_phones_are_blocked(): void
    {
        foreach (range(1, OnlineOrder::LIMIT_PER_IP) as $i) {
            $this->place(['phone' => '86812345'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        $this->assertSame(OnlineOrder::LIMIT_PER_IP, Order::count());
        try {
            $this->place(['phone' => '8685550000']);
            $this->fail('Debió bloquearse');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('store', $e->errors());
        }
        $this->assertSame(OnlineOrder::LIMIT_PER_IP, Order::count());
    }
}
