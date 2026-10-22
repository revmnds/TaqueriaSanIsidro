<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OnlineOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AuditOrderFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        Setting::put('day_ends_at', '0');
    }

    public function test_caja_rejects_a_price_change_before_charging_the_displayed_total(): void
    {
        $product = Product::create(['name' => 'Torta', 'price' => 100]);
        $form = Livewire::test('pages::vender')->set('lines', [['product_id' => $product->id, 'qty' => 1]])->set('received', '100');
        $product->update(['price' => 120]);

        $form->call('charge', 100)->assertReturned(fn ($result) => ! $result['ok'] && $result['prices_changed'] === true && str_contains($result['error'], 'El total cambió'));
        $this->assertSame(0, Order::count());

        $form->set('received', '120')->call('charge', 120)->assertReturned(['ok' => true]);
        $this->assertSame('120.00', Order::sole()->total);
    }

    public function test_charging_a_saved_order_keeps_its_original_pickup_date(): void
    {
        $product = Product::create(['name' => 'Torta', 'price' => 100]);
        Livewire::test('pages::vender')->set('lines', [['product_id' => $product->id, 'qty' => 1]])
            ->set('label', 'Ana')->set('pickupTime', '13:00')->call('saveOpen')->assertReturned(['ok' => true]);
        $order = Order::sole();
        $this->travelTo(now()->setTime(15, 0));

        Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender')->call('charge')->assertReturned(['ok' => true]);

        $this->assertSame('2026-10-15 13:00', $order->fresh()->pickup_at->format('Y-m-d H:i'));
    }

    public function test_undo_does_not_cancel_a_sale_after_the_business_day_changes(): void
    {
        $product = Product::create(['name' => 'Torta', 'price' => 100]);
        $this->travelTo(now()->setTime(23, 59, 30));
        $form = Livewire::test('pages::vender')->set('lines', [['product_id' => $product->id, 'qty' => 1]])
            ->call('charge')->assertReturned(['ok' => true]);
        $order = Order::sole();
        $this->travel(1)->minutes();

        $form->call('undo')->assertDispatched('toast', type: 'error');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_order_saved_yesterday_and_paid_today_can_be_corrected_today(): void
    {
        $product = Product::create(['name' => 'Torta', 'price' => 100]);
        $this->travelTo(now()->setTime(23, 50));
        Livewire::test('pages::vender')->set('lines', [['product_id' => $product->id, 'qty' => 1]])
            ->set('label', 'Ana')->call('saveOpen')->assertReturned(['ok' => true]);
        $order = Order::sole();
        $this->travelTo(now()->addDay()->setTime(0, 10));

        $form = Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender')
            ->call('charge')->assertReturned(['ok' => true]);
        $form->call('undo');

        $this->assertSame('2026-10-15', $order->fresh()->sold_at->format('Y-m-d'));
        $this->assertSame('2026-10-16', $order->fresh()->paid_at->format('Y-m-d'));
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame($order->id, $form->get('undoneId'));
    }

    public function test_online_correction_keeps_customer_tracking_and_contact_on_the_replacement(): void
    {
        $product = Product::create(['name' => 'Taco', 'price' => 20]);
        Setting::put('online_enabled', '1');
        Setting::put('store_mode', 'open');
        RateLimiter::clear('online-order-ip|127.0.0.1');
        $original = OnlineOrder::place([
            'token' => (string) Str::uuid(),
            'name' => 'Juan Pérez',
            'phone' => '8681234567',
            'service' => 'togo',
            'notes' => 'Sin prisa',
            'lines' => [['product_id' => $product->id, 'qty' => 2]],
        ], '127.0.0.1');
        $original->update(['telegram_chat_id' => 31337]);
        $this->assertTrue(OnlineOrder::accept($original));
        $token = $original->public_token;

        $form = Livewire::withQueryParams(['orden' => $original->id])->test('pages::vender')
            ->call('charge')->assertReturned(['ok' => true])->call('undo');
        $this->assertSame('correcting', $original->fresh()->customerStage()['key']);
        $form->call('charge')->assertReturned(['ok' => true]);

        $replacement = Order::where('replaces_order_id', $original->id)->sole();
        $this->assertSame('online', $replacement->source);
        $this->assertSame($token, $replacement->public_token);
        $this->assertSame('8681234567', $replacement->customer_phone);
        $this->assertSame(31337, $replacement->telegram_chat_id);
        $this->assertSame('Sin prisa', $replacement->notes);
        $this->assertNotNull($replacement->paid_at);
        $this->assertSame('preparing', $replacement->customerStage()['key']);
        $this->assertSame('Ya quedó pagado. Lo estamos preparando.', $replacement->customerStage()['text']);
        $this->assertNull($original->fresh()->public_token);
        $this->get(route('pedido', $token))->assertOk()->assertSee('Ya quedó pagado. Lo estamos preparando.');
        $resent = OnlineOrder::place(['token' => $original->operation_token], '127.0.0.1');
        $this->assertSame($replacement->id, $resent->id);
        $this->assertSame($token, $resent->public_token);
    }

    public function test_online_order_rejects_more_than_thirty_lines_without_silently_discarding_items(): void
    {
        $product = Product::create(['name' => 'Taco', 'price' => 20]);
        Setting::put('online_enabled', '1');
        Setting::put('store_mode', 'open');
        RateLimiter::clear('online-order-ip|127.0.0.1');
        $lines = array_fill(0, 31, ['product_id' => $product->id, 'qty' => 1]);

        try {
            OnlineOrder::place([
                'token' => (string) Str::uuid(),
                'name' => 'Juan Pérez',
                'phone' => '8681234567',
                'service' => 'togo',
                'lines' => $lines,
            ], '127.0.0.1');
            $this->fail('El pedido debió rechazarse por exceder el límite.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertSame(0, Order::count());
    }

    public function test_online_order_clamps_a_negative_extreme_quantity_before_integer_conversion(): void
    {
        $product = Product::create(['name' => 'Taco', 'price' => 20]);
        Setting::put('online_enabled', '1');
        Setting::put('store_mode', 'open');

        $order = OnlineOrder::place([
            'token' => (string) Str::uuid(),
            'name' => 'Juan Pérez',
            'phone' => '8681234567',
            'service' => 'togo',
            'lines' => [['product_id' => $product->id, 'qty' => '-1e308']],
        ], '127.0.0.1');

        $this->assertSame(1, $order->items->sole()->quantity);
        $this->assertSame('20.00', $order->total);
    }

    public function test_foreign_extra_and_option_ids_create_new_rows_without_changing_the_other_product(): void
    {
        $first = Product::create(['name' => 'Taco', 'price' => 20]);
        $other = Product::create(['name' => 'Torta', 'price' => 40]);
        $foreignExtra = $other->extras()->create(['name' => 'Queso', 'price' => 5]);
        $foreignGroup = $other->optionGroups()->create(['name' => 'Bebida', 'required' => false]);
        $foreignChoice = $foreignGroup->choices()->create(['name' => 'Agua', 'price' => 0]);

        Livewire::test('pages::productos')->call('edit', $first->id)
            ->set('extras', [['id' => $foreignExtra->id, 'name' => 'Salsa', 'price' => '3', 'ingredient_id' => null, 'quantity' => null]])
            ->set('options', [['id' => $foreignGroup->id, 'name' => 'Tamaño', 'required' => false, 'choices' => [['id' => $foreignChoice->id, 'name' => 'Grande', 'price' => '7']]]])
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Queso', $foreignExtra->fresh()->name);
        $this->assertSame($other->id, $foreignExtra->fresh()->product_id);
        $this->assertSame('Bebida', $foreignGroup->fresh()->name);
        $this->assertSame('Agua', $foreignChoice->fresh()->name);
        $this->assertSame('Salsa', $first->extras()->sole()->name);
        $this->assertNotSame($foreignExtra->id, $first->extras()->sole()->id);
        $this->assertSame('Tamaño', $first->optionGroups()->sole()->name);
        $this->assertNotSame($foreignGroup->id, $first->optionGroups()->sole()->id);
        $this->assertSame('Grande', $first->optionGroups()->sole()->choices()->sole()->name);
        $this->assertNotSame($foreignChoice->id, $first->optionGroups()->sole()->choices()->sole()->id);
    }
}
