<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CashClose;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditPageFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_saving_an_expense_deleted_on_another_device_does_not_recreate_it(): void
    {
        $expense = Expense::create(['category' => 'gas', 'total' => 400, 'spent_on' => today(), 'from_cash' => true]);
        $form = Livewire::test('pages::gastos')->call('edit', $expense->id);
        $expense->delete();

        $form->set('total', '450')->call('save')->assertHasErrors('total');

        $this->assertSame(0, Expense::count());
    }

    public function test_two_forms_save_one_cash_close_for_the_same_day(): void
    {
        $first = Livewire::test('pages::corte');
        $second = Livewire::test('pages::corte');
        $first->set('counted', '500')->call('save')->assertHasNoErrors();
        $second->set('counted', '600')->call('save')->assertHasNoErrors();

        $this->assertSame(1, CashClose::count());
        $this->assertSame('600.00', CashClose::sole()->counted);
    }

    public function test_kitchen_cannot_mark_a_changed_order_ready_using_the_old_card(): void
    {
        $order = Order::create(['daily_number' => 1, 'status' => OrderStatus::Open, 'label' => 'Ana', 'total' => 40, 'sold_at' => now()]);
        $kitchen = Livewire::test('pages::cocina');
        $order->refresh();
        $oldVersion = $order->version;
        $order->update(['total' => 60]);

        $kitchen->call('markReady', $order->id, $oldVersion)->assertDispatched('toast', type: 'error');
        $this->assertNull($order->fresh()->ready_at);

        $kitchen->call('markReady', $order->id, $order->fresh()->version);
        $this->assertNotNull($order->fresh()->ready_at);
    }
}
