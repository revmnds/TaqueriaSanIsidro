<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\BusinessDay;
use App\Support\OnlineOrder;
use App\Support\Store;
use App\Support\Telegram;
use App\Support\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('telegram:alerts')]
#[Description('Envía recordatorios y el resumen de cierre, y caduca pedidos sin respuesta')]
class TelegramAlerts extends Command
{
    public function handle(TelegramNotifier $notifier): int
    {
        try {
            if (Telegram::enabled()) {
                $orders = Order::requested()->where('source', 'online')
                    ->where('created_at', '<', now()->subMinutes(5))->get();

                foreach ($orders as $order) {
                    if (Cache::add('telegram:reminded:'.$order->id, true, now()->addHours(3))) {
                        $notifier->remind($order);
                    }
                }

                if (Store::mode() === 'auto'
                    && Store::isOpenBySchedule(now()->subMinute())
                    && ! Store::isOpenBySchedule(now())
                    && Cache::add('telegram:summary:'.BusinessDay::of(now()->subMinute())->toDateString(), true, now()->addDay())) {
                    $notifier->summary(now()->subMinute());
                }
            }
        } finally {
            // La caducidad sigue funcionando con el bot apagado o si falla un aviso.
            OnlineOrder::expireStale();
        }

        return self::SUCCESS;
    }
}
