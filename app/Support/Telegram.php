<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conexión con la API de Telegram (sin paquetes: solo el cliente HTTP de Laravel) y los ajustes del bot.
 * El token va en la dirección de cada llamada, así que nunca se registra el mensaje de una excepción de red.
 */
class Telegram
{
    /** Dirección de la API de Telegram. Se puede cambiar con TELEGRAM_API para probar contra un servidor de mentira. */
    public static function api(): string
    {
        return rtrim((string) config('services.telegram.api', 'https://api.telegram.org'), '/');
    }

    /** Segundos de espera por llamada: un Telegram lento no debe frenar un pedido. */
    public const TIMEOUT = 5;

    /** Intentos de vincular un chat del taquero con un código, por chat y en total. */
    public const LINK_ATTEMPTS = 5;

    /** Comandos del chat del taquero, en el orden que salen en el menú. */
    public const STAFF_COMMANDS = [
        ['command' => 'panel', 'description' => 'Panel del taquero'],
        ['command' => 'pendientes', 'description' => 'Pedidos por aceptar'],
        ['command' => 'activos', 'description' => 'Pedidos por preparar, cobrar o entregar'],
        ['command' => 'hoy', 'description' => 'Resumen de ventas de hoy'],
        ['command' => 'tienda', 'description' => 'Abrir, cerrar y pedidos en línea'],
        ['command' => 'cliente', 'description' => 'Ver el bot como lo ve un cliente'],
        ['command' => 'ayuda', 'description' => 'Qué puedo hacer aquí'],
    ];

    public static function token(): ?string
    {
        return Store::secret('telegram_token');
    }

    /** El bot está conectado y encendido. */
    public static function enabled(): bool
    {
        return Setting::value('telegram_enabled', '0') === '1' && self::token() !== null;
    }

    public static function username(): ?string
    {
        return Setting::value('telegram_username');
    }

    /** Clave que Telegram manda en cada petición al webhook para demostrar que es él. */
    public static function webhookSecret(): ?string
    {
        return Store::secret('telegram_secret');
    }

    public static function webhookUrl(): string
    {
        return url('/telegram/webhook');
    }

    /** Dirección de la Mini App: la misma página de pedidos, marcada para que sepa que está dentro de Telegram. */
    public static function miniAppUrl(): string
    {
        return rtrim(url('/'), '/').'/?source=telegram';
    }

    /** Enlace para que un cliente de la web reciba avisos de su pedido en Telegram. */
    public static function orderLink(string $publicToken): ?string
    {
        return self::username() ? 'https://t.me/'.self::username().'?start=pedido_'.$publicToken : null;
    }

    // ---- API ----

    /**
     * Llama a un método de la API. Devuelve su "result" o null si falla (sin lanzar nada).
     *
     * @param  array<string, mixed>  $params
     */
    public static function call(string $method, array $params = [], int $timeout = self::TIMEOUT): mixed
    {
        $token = self::token();

        if ($token === null || Cache::has('telegram:down')) {
            return null;
        }

        try {
            $response = Http::timeout($timeout)->connectTimeout(3)->asJson()->post(self::api().'/bot'.$token.'/'.$method, $params);
        } catch (ConnectionException) {
            // Telegram no responde: se deja de intentar un minuto para no frenar cada pedido.
            Cache::put('telegram:down', true, 60);
            Log::warning('Telegram no responde ('.$method.').');

            return null;
        } catch (Throwable $e) {
            Log::warning('Telegram falló ('.$method.'): '.get_class($e));

            return null;
        }

        if ($response->serverError()) {
            Cache::put('telegram:down', true, 60);
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::info('Telegram rechazó '.$method.': '.mb_substr((string) $response->json('description'), 0, 120));

            return null;
        }

        return $response->json('result') ?? true;
    }

    /** Texto con formato HTML de Telegram: lo que escribe el cliente se escapa siempre. */
    public static function esc(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Manda un mensaje; devuelve su id o null.
     *
     * @param  array<string, mixed>|null  $markup
     */
    public static function send(int|string $chat, string $text, ?array $markup = null): ?int
    {
        $result = self::call('sendMessage', array_filter([
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $markup,
        ], fn ($v) => $v !== null));

        return is_array($result) ? ($result['message_id'] ?? null) : null;
    }

    /** @param  array<string, mixed>|null  $markup */
    public static function edit(int|string $chat, int $messageId, string $text, ?array $markup = null): bool
    {
        return self::call('editMessageText', [
            'chat_id' => $chat,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $markup ?? ['inline_keyboard' => []],
        ]) !== null;
    }

    public static function answerCallback(string $id, ?string $text = null, bool $alert = false): void
    {
        self::call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text ? mb_substr($text, 0, 190) : null, 'show_alert' => $alert ?: null]));
    }

    // ---- Conectar el bot ----

    /**
     * Guarda el token tras comprobarlo con Telegram (getMe). Devuelve el usuario del bot o null si el token no sirve.
     *
     * @return array<string, mixed>|null
     */
    public static function connect(string $token): ?array
    {
        $response = null;

        try {
            $response = Http::timeout(self::TIMEOUT)->get(self::api().'/bot'.$token.'/getMe');
        } catch (Throwable) {
            return null;
        }

        $me = $response->successful() && $response->json('ok') ? $response->json('result') : null;

        if (! is_array($me) || empty($me['is_bot'])) {
            return null;
        }

        Cache::forget('telegram:down');
        Store::putSecret('telegram_token', $token);
        Setting::put('telegram_username', $me['username'] ?? null);
        Store::putSecret('telegram_secret', Store::secret('telegram_secret') ?? Str::random(48));
        Setting::put('telegram_enabled', '1');

        return $me;
    }

    /** ¿La dirección de la app sirve para un webhook y una Mini App? Telegram exige https. */
    public static function canUseWebhook(): bool
    {
        return str_starts_with(url('/'), 'https://');
    }

    /** Registra el webhook, los comandos y el botón de menú que abre la Mini App. */
    public static function register(): bool
    {
        if (! self::canUseWebhook()) {
            return false;
        }

        $ok = self::call('setWebhook', [
            'url' => self::webhookUrl(),
            'secret_token' => self::webhookSecret(),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
            'max_connections' => 10,
        ]) !== null;

        self::call('setMyCommands', ['commands' => [
            ['command' => 'pedido', 'description' => 'Hacer un pedido'],
            ['command' => 'menu', 'description' => 'Ver el menú y los precios'],
            ['command' => 'horario', 'description' => 'Horario y si estamos abiertos'],
            ['command' => 'estado', 'description' => 'Cómo va mi último pedido'],
            ['command' => 'ayuda', 'description' => 'Qué puedo hacer aquí'],
        ]]);

        self::call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'Pedir', 'web_app' => ['url' => self::miniAppUrl()]]]);

        return $ok;
    }

    /** Apaga el bot: quita el webhook y olvida las credenciales y los chats del taquero. */
    public static function disconnect(): void
    {
        foreach (self::staffChats() as $chat) {
            self::applyStaffUi((int) $chat['id'], false);
        }

        self::call('deleteWebhook', ['drop_pending_updates' => true]);

        foreach (['telegram_token', 'telegram_secret', 'telegram_username', 'telegram_enabled', 'telegram_staff'] as $key) {
            Setting::put($key, null);
        }

        Cache::forget('telegram:link-code');
    }

    // ---- Chats del taquero ----

    /** @return array<int, array{id: int, name: string}> */
    public static function staffChats(): array
    {
        $chats = json_decode((string) Setting::value('telegram_staff'), true);

        return is_array($chats) ? array_values(array_filter($chats, fn ($c) => is_array($c) && isset($c['id']))) : [];
    }

    public static function isStaff(int|string|null $chatId): bool
    {
        return $chatId !== null && collect(self::staffChats())->contains(fn ($c) => (string) $c['id'] === (string) $chatId);
    }

    public static function addStaff(int $chatId, string $name): void
    {
        self::changeStaff(fn ($chats) => $chats->reject(fn ($c) => (string) $c['id'] === (string) $chatId)
            ->push(['id' => $chatId, 'name' => mb_substr(Names::clean($name), 0, 60) ?: 'Chat '.$chatId]));
    }

    public static function removeStaff(int|string $chatId): void
    {
        self::changeStaff(fn ($chats) => $chats->reject(fn ($c) => (string) $c['id'] === (string) $chatId));
        DB::table('telegram_notices')->where('chat_id', $chatId)->delete();
        self::applyStaffUi((int) $chatId, false);
    }

    /**
     * Cambia la lista de chats del taquero de uno en uno y sobre lo último guardado: dos cambios a la vez
     * no deben perder uno (un chat desvinculado no puede volver a aparecer).
     */
    protected static function changeStaff(callable $change): void
    {
        Cache::lock('telegram:staff', 10)->block(5, function () use ($change) {
            Setting::flush();
            $chats = $change(collect(self::staffChats()))->values()->all();
            Setting::put('telegram_staff', $chats ? json_encode($chats) : null);
        });
    }

    /** ¿Este chat del taquero está viendo el bot como un cliente? Por defecto ve el panel de taquero. */
    public static function usesCustomerView(int|string $chatId): bool
    {
        return (bool) (collect(self::staffChats())->first(fn ($c) => (string) $c['id'] === (string) $chatId)['customer_view'] ?? false);
    }

    /** Cambia lo que ve el chat del taquero: el panel de taquero o el bot como lo ve un cliente. */
    public static function setCustomerView(int $chatId, bool $customer): void
    {
        self::changeStaff(fn ($chats) => $chats->map(fn ($c) => (string) $c['id'] === (string) $chatId ? [...$c, 'customer_view' => $customer] : $c));
        self::applyStaffUi($chatId, ! $customer);
    }

    /**
     * Comandos y botón de menú de un chat: los del taquero en el panel, y los de siempre (con el botón Pedir)
     * como cliente o al desvincular.
     */
    public static function applyStaffUi(int $chatId, bool $admin): void
    {
        $scope = ['type' => 'chat', 'chat_id' => $chatId];

        if ($admin) {
            self::call('setMyCommands', ['commands' => self::STAFF_COMMANDS, 'scope' => $scope]);
            self::call('setChatMenuButton', ['chat_id' => $chatId, 'menu_button' => ['type' => 'commands']]);

            return;
        }

        self::call('deleteMyCommands', ['scope' => $scope]);
        self::call('setChatMenuButton', ['chat_id' => $chatId, 'menu_button' => ['type' => 'web_app', 'text' => 'Pedir', 'web_app' => ['url' => self::miniAppUrl()]]]);
    }

    /** Código de 6 dígitos para vincular el Telegram del taquero; vale 10 minutos y una sola vez. */
    public static function newLinkCode(): string
    {
        return Cache::lock('telegram:link-code-lock', 10)->block(5, function () {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put('telegram:link-code', Crypt::encryptString($code), 600);

            return $code;
        });
    }

    /** Intenta vincular un chat con el código que escribió. Limitado por chat y en total para que no se adivine. */
    public static function consumeLinkCode(int $chatId, string $typed, string $name): bool
    {
        $keys = ['telegram-link|'.$chatId, 'telegram-link-all'];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::LINK_ATTEMPTS)) {
                return false;
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, 600);
        }

        try {
            return Cache::lock('telegram:link-code-lock', 10)->block(5, function () use ($chatId, $typed, $name) {
                try {
                    $stored = ($encrypted = Cache::get('telegram:link-code')) ? Crypt::decryptString($encrypted) : null;
                } catch (Throwable) {
                    $stored = null;
                }

                if ($stored === null || ! hash_equals($stored, trim($typed))) {
                    return false;
                }

                self::addStaff($chatId, $name);
                Cache::forget('telegram:link-code');

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    // ---- Mini App ----

    /**
     * Comprueba los datos que Telegram le da a la Mini App (initData) y devuelve el usuario si son auténticos.
     * Regla oficial: HMAC-SHA256 del texto ordenado, con clave HMAC("WebAppData", token). Caduca a las 24 horas.
     *
     * @return array{id: int, first_name?: string, last_name?: string, username?: string}|null
     */
    public static function verifyInitData(?string $initData, ?int $now = null, int $maxAge = 86400): ?array
    {
        $token = self::token();

        if ($token === null || ! is_string($initData) || $initData === '' || strlen($initData) > 4096) {
            return null;
        }

        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? null;
        unset($fields['hash']);

        if (! is_string($hash) || ! preg_match('/^[0-9a-f]{64}$/', $hash) || array_filter($fields, 'is_array')) {
            return null;
        }

        ksort($fields);
        $check = collect($fields)->map(fn ($value, $key) => $key.'='.$value)->join("\n");
        $expected = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

        if (! hash_equals($expected, $hash)) {
            return null;
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);
        $now ??= time();

        if ($authDate <= 0 || $now - $authDate > $maxAge || $authDate - $now > 300) {
            return null;
        }

        $user = json_decode((string) ($fields['user'] ?? ''), true);

        return is_array($user) && isset($user['id']) && is_int($user['id']) ? $user : null;
    }
}
