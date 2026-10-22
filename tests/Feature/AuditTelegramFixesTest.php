<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Store;
use App\Support\Telegram;
use App\Support\TelegramBot;
use App\Support\TelegramNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditTelegramFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function enableBot(): void
    {
        Store::putSecret('telegram_token', '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');
        Store::putSecret('telegram_secret', 'secret-for-test');
        Setting::put('telegram_enabled', '1');
    }

    public function test_manual_store_modes_do_not_promise_schedule_opening_or_closing_times(): void
    {
        $at = CarbonImmutable::parse('2026-10-15 21:00', config('app.timezone'));
        Setting::put('store_mode', 'closed');

        $closed = Store::status($at);

        $this->assertFalse($closed['open']);
        $this->assertNull($closed['opens_at']);

        Setting::put('store_mode', 'open');

        $open = Store::status($at);

        $this->assertTrue($open['open']);
        $this->assertNull($open['closes_at']);
    }

    public function test_closing_summary_counts_the_day_that_just_ended_while_hoy_uses_the_current_day(): void
    {
        $this->enableBot();
        Telegram::addStaff(5551, 'Taquero');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 00:00', config('app.timezone')));
        Order::create([
            'source' => 'online',
            'status' => OrderStatus::Paid,
            'sold_at' => now()->subMinutes(10),
            'paid_at' => now()->subMinutes(5),
            'total' => 40,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        TelegramNotifier::summary(now()->subMinute());

        $text = Http::recorded()->first()[0]['text'];
        $this->assertStringContainsString('1 ventas por', $text);
        $this->assertStringContainsString('En línea: 1', $text);
        $this->assertStringContainsString('0 ventas por', TelegramNotifier::summaryText());
    }

    public function test_long_staff_notice_links_to_the_complete_current_order_in_the_admin_page(): void
    {
        $this->enableBot();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        $order = Order::create([
            'source' => 'online',
            'status' => OrderStatus::Requested,
            'label' => 'Ana',
            'service' => 'togo',
            'sold_at' => now(),
            'total' => 600,
        ]);
        $order->items()->createMany(collect(range(1, 30))->map(fn (int $number) => [
            'product_name' => 'Taco especial '.$number,
            'quantity' => 1,
            'unit_price' => 20,
            'unit_cost' => 0,
            'subtotal' => 20,
            'notes' => str_repeat('&', 120),
        ])->all());

        TelegramNotifier::sendStaffNotice($order->load('items'), 5551);

        $messages = Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))
            ->values();
        $this->assertSame(1, $messages->count());
        $this->assertStringContainsString(route('pedidos'), $messages[0]['text']);
        $this->assertSame('HTML', $messages[0]['parse_mode']);
        $this->assertLessThanOrEqual(3500, mb_strlen($messages[0]['text']));
        $this->assertStringNotContainsString('Taco especial 30', $messages[0]['text']);
        $this->assertDatabaseHas('telegram_notices', ['order_id' => $order->id, 'chat_id' => 5551, 'message_id' => 900]);

        $this->actingAs(User::factory()->create())->get(route('pedidos'))->assertOk()->assertSee('Taco especial 30');
    }

    public function test_large_menu_is_sent_in_complete_html_messages(): void
    {
        $this->enableBot();
        foreach (range(1, 10) as $number) {
            Product::create(['name' => 'Producto '.$number.' '.str_repeat('&', 200), 'category' => 'Tacos', 'price' => 20]);
        }
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        TelegramBot::handle(['message' => ['chat' => ['id' => 7771, 'type' => 'private'], 'from' => ['first_name' => 'Ana'], 'text' => '/menu']]);

        $messages = Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))
            ->values();
        $this->assertGreaterThan(1, $messages->count());
        foreach ($messages as $message) {
            $this->assertLessThanOrEqual(3500, mb_strlen($message['text']));
            $this->assertStringStartsWith('<b>Tacos</b>', $message['text']);
        }
        $this->assertStringContainsString('Producto 10', $messages->pluck('text')->join("\n"));
    }

    public function test_removing_staff_deletes_old_notices_and_future_changes_do_not_edit_them(): void
    {
        $this->enableBot();
        Telegram::addStaff(5551, 'Taquero');
        $order = Order::create(['source' => 'online', 'status' => OrderStatus::Requested, 'sold_at' => now(), 'total' => 40]);
        DB::table('telegram_notices')->insert(['order_id' => $order->id, 'chat_id' => 5551, 'message_id' => 900, 'created_at' => now(), 'updated_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        Telegram::removeStaff(5551);
        $this->assertDatabaseMissing('telegram_notices', ['order_id' => $order->id, 'chat_id' => 5551]);

        DB::table('telegram_notices')->insert(['order_id' => $order->id, 'chat_id' => 5551, 'message_id' => 901, 'created_at' => now(), 'updated_at' => now()]);
        TelegramNotifier::refreshStaff($order);

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/editMessageText'));
    }

    public function test_link_code_remains_available_when_another_consumer_holds_the_lock(): void
    {
        $code = Telegram::newLinkCode();
        $lock = Cache::lock('telegram:link-code-lock', 10);
        $this->assertTrue($lock->get());

        try {
            $this->assertFalse(Telegram::consumeLinkCode(5551, $code, 'Taquero'));
        } finally {
            $lock->release();
        }

        $this->assertTrue(Telegram::consumeLinkCode(5551, $code, 'Taquero'));
        $this->assertTrue(Telegram::isStaff(5551));
    }
}
