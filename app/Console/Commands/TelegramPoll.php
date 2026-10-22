<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Telegram;
use App\Support\TelegramBot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('telegram:poll {--once : Consulta una sola vez}')]
#[Description('Recibe mensajes de Telegram en desarrollo sin HTTPS público')]
class TelegramPoll extends Command
{
    public function handle(TelegramBot $bot): int
    {
        if (! Telegram::enabled()) {
            $this->error('El bot de Telegram no está conectado o está apagado. Conéctalo en Tienda en línea.');

            return self::FAILURE;
        }

        $running = true;

        if (function_exists('pcntl_signal')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$running): void {
                $running = false;
            });
        }

        if (Telegram::call('deleteWebhook', ['drop_pending_updates' => false]) === null) {
            $this->error('No se pudo quitar el webhook de Telegram. Intenta de nuevo en un minuto.');

            return self::FAILURE;
        }

        $botId = explode(':', Telegram::token() ?? '', 2)[0];
        $offsetKey = 'telegram:offset:'.$botId;
        $offset = max(0, (int) Cache::get($offsetKey, 0));
        $this->info('Consultando Telegram. Para terminar usa Ctrl+C.');

        while ($running) {
            $updates = Telegram::call('getUpdates', [
                'offset' => $offset,
                'timeout' => 25,
                'allowed_updates' => ['message', 'callback_query'],
            ], timeout: 35);

            // Proceso largo: los ajustes pudieron cambiar desde la web, incluso si Telegram no respondió.
            Setting::flush();
            if (explode(':', Telegram::token() ?? '', 2)[0] !== $botId) {
                $this->warn('El bot cambió mientras se consultaban mensajes. Reinicia telegram:poll.');

                return self::FAILURE;
            }

            if (is_array($updates)) {
                foreach ($updates as $update) {
                    if (! $running) {
                        break;
                    }

                    if (! is_array($update) || ! isset($update['update_id']) || ! is_int($update['update_id']) || $update['update_id'] < $offset) {
                        continue;
                    }

                    try {
                        $bot->handle($update);
                    } catch (Throwable $e) {
                        report($e);
                        $this->warn('No se pudo procesar la actualización '.$update['update_id'].'. Se continúa con la siguiente.');
                    }

                    // Un mensaje que falla tampoco debe bloquear los siguientes al reiniciar.
                    $offset = $update['update_id'] + 1;
                    Cache::forever($offsetKey, $offset);
                }
            } else {
                $this->warn('Telegram no respondió. Se volverá a intentar.');
            }

            if ($this->option('once')) {
                return is_array($updates) ? self::SUCCESS : self::FAILURE;
            }

            // call() también devuelve null durante los 60 segundos de telegram:down.
            if (! is_array($updates) && $running) {
                sleep(1);
            }
        }

        $this->info('Consulta de Telegram terminada.');

        return self::SUCCESS;
    }
}
