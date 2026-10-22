<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Store;
use App\Support\Telegram;
use App\Support\TelegramBot;
use App\Support\TelegramNotifier;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class TelegramCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([]);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(20, 30));

        TelegramCommandsBotFake::$updates = [];
        TelegramCommandsNotifierFake::$reminded = [];
        TelegramCommandsNotifierFake::$summaries = 0;
        TelegramCommandsNotifierFake::$summaryMoments = [];
        TelegramCommandsNotifierFake::$fail = false;
        $this->app->instance(TelegramBot::class, new TelegramCommandsBotFake);
        $this->app->instance(TelegramNotifier::class, new TelegramCommandsNotifierFake);
    }

    private function enableBot(): void
    {
        Store::putSecret('telegram_token', '123:abc');
        Setting::put('telegram_enabled', '1');
    }

    private function waitingOrder(int $minutes, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'source' => 'online',
            'status' => OrderStatus::Requested,
            'sold_at' => now(),
            'total' => 40,
        ], $attributes));

        $order->forceFill(['created_at' => now()->subMinutes($minutes)])->saveQuietly();

        return $order;
    }

    public function test_poll_once_dispatches_updates_and_resumes_from_the_cached_offset(): void
    {
        $this->enableBot();
        Cache::forever('telegram:offset:123', 41);
        $updates = [
            ['update_id' => 41, 'message' => ['text' => '/ayuda']],
            ['update_id' => 42, 'callback_query' => ['id' => 'button-42']],
        ];
        $offsets = [];
        $timeouts = [];

        Http::fake(function (Request $request, array $options) use ($updates, &$offsets, &$timeouts) {
            if (str_ends_with($request->url(), '/deleteWebhook')) {
                $this->assertFalse($request['drop_pending_updates']);

                return Http::response(['ok' => true, 'result' => true]);
            }

            $this->assertStringEndsWith('/getUpdates', $request->url());
            $this->assertSame(25, $request['timeout']);
            $this->assertSame(['message', 'callback_query'], $request['allowed_updates']);
            $offsets[] = $request['offset'];
            $timeouts[] = $options['timeout'];

            return Http::response(['ok' => true, 'result' => count($offsets) === 1 ? $updates : []]);
        });

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();
        $this->assertSame($updates, TelegramCommandsBotFake::$updates);
        $this->assertSame(43, Cache::get('telegram:offset:123'));

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame([41, 43], $offsets);
        $this->assertEquals([35, 35], $timeouts);
        $this->assertSame($updates, TelegramCommandsBotFake::$updates);
        Http::assertSentCount(4);
        $this->assertStringEndsWith('/deleteWebhook', Http::recorded()->first()[0]->url());
    }

    public function test_poll_reports_a_failed_update_and_continues_with_the_next_one(): void
    {
        $this->enableBot();
        Exceptions::fake();
        $updates = [
            ['update_id' => 10, 'fail' => true],
            ['update_id' => 11, 'message' => ['text' => '/menu']],
        ];
        Http::fake([
            Telegram::api().'/bot123:abc/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            Telegram::api().'/bot123:abc/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutputToContain('Se continúa con la siguiente')
            ->assertSuccessful();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame($updates, TelegramCommandsBotFake::$updates);
        $this->assertSame(12, Cache::get('telegram:offset:123'));
    }

    public function test_poll_skips_updates_older_than_the_offset(): void
    {
        $this->enableBot();
        Cache::forever('telegram:offset:123', 12);
        Http::fake([
            Telegram::api().'/bot123:abc/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            Telegram::api().'/bot123:abc/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 11], ['update_id' => 12],
            ]]),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame([['update_id' => 12]], TelegramCommandsBotFake::$updates);
        $this->assertSame(13, Cache::get('telegram:offset:123'));
    }

    public function test_poll_starts_at_the_new_bots_own_offset_after_switching_bots(): void
    {
        $this->enableBot();
        Cache::forever('telegram:offset:123', 400);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/deleteWebhook')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            $this->assertSame(0, $request['offset']);

            return Http::response(['ok' => true, 'result' => [['update_id' => 1]]]);
        });
        Store::putSecret('telegram_token', '456:other-bot-token');

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame([['update_id' => 1]], TelegramCommandsBotFake::$updates);
        $this->assertSame(400, Cache::get('telegram:offset:123'));
        $this->assertSame(2, Cache::get('telegram:offset:456'));
    }

    public function test_poll_does_not_process_old_bot_updates_after_the_bot_changes_mid_request(): void
    {
        $this->enableBot();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/deleteWebhook')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            Store::putSecret('telegram_token', '456:other-bot-token');

            return Http::response(['ok' => true, 'result' => [['update_id' => 41]]]);
        });

        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutputToContain('El bot cambió')
            ->assertFailed();

        $this->assertSame([], TelegramCommandsBotFake::$updates);
        $this->assertFalse(Cache::has('telegram:offset:123'));
        $this->assertFalse(Cache::has('telegram:offset:456'));
    }

    public function test_poll_explains_when_the_bot_is_not_connected(): void
    {
        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutputToContain('no está conectado o está apagado')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_poll_once_preserves_the_offset_when_telegram_is_down(): void
    {
        $this->enableBot();
        Cache::forever('telegram:offset:123', 42);
        Http::fake([
            Telegram::api().'/bot123:abc/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            Telegram::api().'/bot123:abc/getUpdates' => Http::response([], 503),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutputToContain('Telegram no respondió')
            ->assertFailed();

        $this->assertSame(42, Cache::get('telegram:offset:123'));
        $this->assertSame([], TelegramCommandsBotFake::$updates);
        Http::assertSentCount(2);
    }

    public function test_alerts_remind_once_after_five_minutes_and_ignore_other_orders(): void
    {
        $this->enableBot();
        $waiting = $this->waitingOrder(6);
        $recent = $this->waitingOrder(4);
        $boundary = $this->waitingOrder(5);
        $this->waitingOrder(10, ['status' => OrderStatus::Open]);
        $this->waitingOrder(10, ['source' => 'pos']);

        $this->artisan('telegram:alerts')->assertSuccessful();
        $this->artisan('telegram:alerts')->assertSuccessful();

        $this->assertSame([$waiting->id], TelegramCommandsNotifierFake::$reminded);
        $this->assertTrue(Cache::has('telegram:reminded:'.$waiting->id));
        $this->assertFalse(Cache::has('telegram:reminded:'.$recent->id));
        $this->assertFalse(Cache::has('telegram:reminded:'.$boundary->id));
        $this->assertSame(0, TelegramCommandsNotifierFake::$summaries);

        $this->travel(179)->minutes();
        $this->assertTrue(Cache::has('telegram:reminded:'.$waiting->id));
        $this->travel(2)->minutes();
        $this->assertFalse(Cache::has('telegram:reminded:'.$waiting->id));
    }

    public function test_alerts_expire_stale_orders_even_with_the_bot_disabled(): void
    {
        $stale = $this->waitingOrder(91);
        $waiting = $this->waitingOrder(6);

        $this->artisan('telegram:alerts')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $stale->fresh()->status);
        $this->assertSame(OrderStatus::Requested, $waiting->fresh()->status);
        $this->assertSame([], TelegramCommandsNotifierFake::$reminded);
        $this->assertSame(0, TelegramCommandsNotifierFake::$summaries);
        $this->assertFalse(Cache::has('telegram:reminded:'.$waiting->id));
        Http::assertNothingSent();
    }

    public function test_alerts_still_expire_orders_if_a_notifier_throws(): void
    {
        $this->enableBot();
        TelegramCommandsNotifierFake::$fail = true;
        $stale = $this->waitingOrder(91);

        try {
            $this->artisan('telegram:alerts')->run();
            $this->fail('Se esperaba el error del notificador.');
        } catch (RuntimeException $e) {
            $this->assertSame('Error de prueba del notificador.', $e->getMessage());
        }

        $this->assertSame(OrderStatus::Cancelled, $stale->fresh()->status);
    }

    public function test_alerts_send_the_closing_summary_once_per_day(): void
    {
        $this->enableBot();
        Setting::put('store_mode', 'auto');
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(0, 0));

        $this->artisan('telegram:alerts')->assertSuccessful();
        $this->artisan('telegram:alerts')->assertSuccessful();

        $this->assertSame(1, TelegramCommandsNotifierFake::$summaries);
        $this->assertTrue(Cache::has('telegram:summary:2026-10-15'));

        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(0, 0));
        $this->artisan('telegram:alerts')->assertSuccessful();

        $this->assertSame(2, TelegramCommandsNotifierFake::$summaries);
        $this->assertTrue(Cache::has('telegram:summary:2026-10-16'));
        $this->assertSame(['2026-10-15 23:59', '2026-10-16 23:59'], TelegramCommandsNotifierFake::$summaryMoments);
    }

    public function test_alerts_send_no_summary_in_manual_modes(): void
    {
        $this->enableBot();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(0, 0));

        foreach (['open', 'closed'] as $mode) {
            Setting::put('store_mode', $mode);
            $this->artisan('telegram:alerts')->assertSuccessful();
        }

        $this->assertSame(0, TelegramCommandsNotifierFake::$summaries);
        $this->assertFalse(Cache::has('telegram:summary:2026-10-16'));
    }

    public function test_alerts_require_a_schedule_transition_for_the_summary(): void
    {
        $this->enableBot();
        $this->artisan('telegram:alerts')->assertSuccessful();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(0, 2));
        $this->artisan('telegram:alerts')->assertSuccessful();

        $this->assertSame(0, TelegramCommandsNotifierFake::$summaries);
        $this->assertFalse(Cache::has('telegram:summary:2026-10-16'));
    }

    public function test_alerts_send_no_closing_summary_with_the_bot_disabled(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(0, 0));

        $this->artisan('telegram:alerts')->assertSuccessful();

        $this->assertSame(0, TelegramCommandsNotifierFake::$summaries);
        $this->assertFalse(Cache::has('telegram:summary:2026-10-16'));
        Http::assertNothingSent();
    }

    public function test_webhook_explains_how_to_work_without_https(): void
    {
        $this->enableBot();
        URL::forceRootUrl('http://taqueria.test');
        URL::forceScheme('http');

        $this->artisan('telegram:webhook')
            ->expectsOutputToContain('HTTPS para el webhook. En desarrollo usa php artisan telegram:poll.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_webhook_registers_with_the_secret_over_https(): void
    {
        $this->enableBot();
        Store::putSecret('telegram_secret', 'secret-for-test');
        URL::forceRootUrl('https://taqueria.test');
        URL::forceScheme('https');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('telegram:webhook')
            ->expectsOutputToContain('Webhook de Telegram registrado')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://taqueria.test/telegram/webhook'
            && $request['secret_token'] === 'secret-for-test');
    }

    public function test_webhook_can_be_deleted_over_http_without_dropping_pending_updates(): void
    {
        $this->enableBot();
        URL::forceRootUrl('http://taqueria.test');
        URL::forceScheme('http');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('telegram:webhook', ['--delete' => true])
            ->expectsOutputToContain('Webhook de Telegram eliminado')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/deleteWebhook')
            && $request['drop_pending_updates'] === false);
    }
}

/** Dobles con la misma API estática, resueltos mediante el contenedor de los comandos. */
class TelegramCommandsBotFake extends TelegramBot
{
    public static array $updates = [];

    public static function handle(array $update): void
    {
        self::$updates[] = $update;

        if ($update['fail'] ?? false) {
            throw new RuntimeException('Error de prueba del bot.');
        }
    }
}

class TelegramCommandsNotifierFake extends TelegramNotifier
{
    public static array $reminded = [];

    public static int $summaries = 0;

    public static array $summaryMoments = [];

    public static bool $fail = false;

    public static function remind(Order $order): void
    {
        self::$reminded[] = $order->id;

        if (self::$fail) {
            throw new RuntimeException('Error de prueba del notificador.');
        }
    }

    public static function summary(?CarbonInterface $at = null): void
    {
        self::$summaries++;
        self::$summaryMoments[] = $at?->format('Y-m-d H:i');
    }
}
