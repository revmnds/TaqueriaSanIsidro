<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Las reglas de la tienda en línea: horario, si la taquería está abierta o cerrada, y qué se
 * puede pedir. Abierta y "pedidos en línea" son cosas separadas: puede estar abierta sin
 * recibir pedidos en línea (solo se vende en el puesto).
 */
class Store
{
    /** Lunes primero, como en México. */
    public const DAYS = [
        'mon' => 'Lunes',
        'tue' => 'Martes',
        'wed' => 'Miércoles',
        'thu' => 'Jueves',
        'fri' => 'Viernes',
        'sat' => 'Sábado',
        'sun' => 'Domingo',
    ];

    public const MODES = ['auto', 'open', 'closed'];

    public const MESSAGE_MAX = 200;

    public const DELIVERY_OFF_MESSAGE = 'La taquería no está aceptando órdenes a domicilio, solo para pasar a recoger.';

    /** Jueves a domingo, de 8 pm a 12 am. */
    public static function defaultSchedule(): array
    {
        return collect(self::DAYS)->map(fn ($label, $day) => in_array($day, ['thu', 'fri', 'sat', 'sun'], true)
            ? ['open' => true, 'from' => '20:00', 'to' => '00:00']
            : ['open' => false, 'from' => '20:00', 'to' => '00:00'])->all();
    }

    /**
     * Horario por día: ['mon' => ['open' => bool, 'from' => 'HH:MM', 'to' => 'HH:MM'], ...].
     * Si "to" es igual o menor que "from", cierra al día siguiente (00:00 es la medianoche).
     *
     * @return array<string, array{open: bool, from: string, to: string}>
     */
    public static function schedule(): array
    {
        $stored = json_decode((string) Setting::value('store_schedule'), true);
        $schedule = self::defaultSchedule();

        if (! is_array($stored)) {
            return $schedule;
        }

        foreach ($schedule as $day => $default) {
            $row = $stored[$day] ?? null;

            if (is_array($row) && self::validTime($row['from'] ?? null) && self::validTime($row['to'] ?? null)) {
                $schedule[$day] = ['open' => (bool) ($row['open'] ?? false), 'from' => $row['from'], 'to' => $row['to']];
            }
        }

        return $schedule;
    }

    /** @param  array<string, array{open: bool, from: string, to: string}>  $schedule */
    public static function saveSchedule(array $schedule): void
    {
        Setting::put('store_schedule', json_encode(collect(self::DAYS)->map(fn ($label, $day) => [
            'open' => (bool) ($schedule[$day]['open'] ?? false),
            'from' => $schedule[$day]['from'] ?? '20:00',
            'to' => $schedule[$day]['to'] ?? '00:00',
        ])->all()));
    }

    public static function validTime(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    /** 'auto' sigue el horario; 'open' y 'closed' lo ignoran hasta que el taquero lo cambie. */
    public static function mode(): string
    {
        $mode = Setting::value('store_mode', 'auto');

        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /** Aviso para los clientes: "Cerrado por mal clima". */
    public static function message(): ?string
    {
        return Setting::value('store_message');
    }

    /** ¿El horario dice que está abierto en ese momento? Cuenta también lo que viene de la noche anterior. */
    public static function isOpenBySchedule(?CarbonInterface $at = null): bool
    {
        return self::currentWindow($at) !== null;
    }

    /** Abierta ahora, tomando en cuenta lo que el taquero fijó a mano. */
    public static function isOpen(?CarbonInterface $at = null): bool
    {
        return match (self::mode()) {
            'open' => true,
            'closed' => false,
            default => self::isOpenBySchedule($at),
        };
    }

    /**
     * Estado para mostrar a los clientes.
     *
     * @return array{open: bool, manual: bool, message: ?string, closes_at: ?CarbonImmutable, opens_at: ?CarbonImmutable}
     */
    public static function status(?CarbonInterface $at = null): array
    {
        $at = self::now($at);
        $mode = self::mode();
        $window = self::currentWindow($at);
        $open = match ($mode) {
            'open' => true,
            'closed' => false,
            default => $window !== null,
        };

        return [
            'open' => $open,
            'manual' => $mode !== 'auto',
            'message' => self::message(),
            'closes_at' => $mode === 'auto' && $open ? $window['end'] ?? null : null,
            'opens_at' => $mode === 'auto' && ! $open ? self::nextOpening($at) : null,
        ];
    }

    /** Teléfono de la taquería que ven los clientes (10 dígitos). */
    public static function phone(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) Setting::value('store_phone'));

        return strlen($digits) === 10 ? $digits : null;
    }

    public static function onlineEnabled(): bool
    {
        return Setting::value('online_enabled', '0') === '1';
    }

    public static function deliveryEnabled(): bool
    {
        return Setting::value('online_delivery', '0') === '1';
    }

    public static function deliveryOffMessage(): string
    {
        return Setting::value('online_delivery_message') ?: self::DELIVERY_OFF_MESSAGE;
    }

    /**
     * ¿Un cliente puede mandar un pedido ahora? Hace falta que los pedidos en línea estén activados
     * y que la taquería esté abierta. Devuelve el motivo para decírselo al cliente.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public static function canOrderOnline(?CarbonInterface $at = null): array
    {
        if (! self::onlineEnabled()) {
            return ['ok' => false, 'reason' => 'online_off'];
        }

        if (! self::isOpen($at)) {
            return ['ok' => false, 'reason' => 'closed'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** "Jueves a domingo, de 8:00 pm a 12:00 am", agrupando días seguidos con el mismo horario. */
    public static function summary(): string
    {
        $open = collect(self::schedule())->filter(fn ($row) => $row['open']);

        if ($open->isEmpty()) {
            return 'Sin horario';
        }

        $groups = [];
        foreach ($open as $day => $row) {
            $key = $row['from'].'-'.$row['to'];
            $last = array_key_last($groups);
            $previousDay = $last !== null ? end($groups[$last]['days']) : null;

            if ($last !== null && $groups[$last]['key'] === $key && self::consecutive($previousDay, $day)) {
                $groups[$last]['days'][] = $day;
            } else {
                $groups[] = ['key' => $key, 'days' => [$day], 'from' => $row['from'], 'to' => $row['to']];
            }
        }

        return collect($groups)->map(function (array $group) {
            $days = count($group['days']) > 1
                ? self::DAYS[$group['days'][0]].' a '.mb_strtolower(self::DAYS[end($group['days'])])
                : self::DAYS[$group['days'][0]];

            return $days.', de '.self::label($group['from']).' a '.self::label($group['to']);
        })->join('; ');
    }

    /** "8:00 pm", "12:00 am". */
    public static function label(string $time): string
    {
        return CarbonImmutable::createFromFormat('H:i', $time)->format('g:i a');
    }

    /** Credenciales de Mercado Pago, listas para la integración. El token y el secreto se guardan cifrados. */
    public static function mercadoPago(): array
    {
        return [
            'enabled' => Setting::value('mp_enabled', '0') === '1',
            'mode' => Setting::value('mp_mode', 'test') === 'live' ? 'live' : 'test',
            'public_key' => Setting::value('mp_public_key'),
            'access_token' => self::secret('mp_access_token'),
            'webhook_secret' => self::secret('mp_webhook_secret'),
            'required' => Setting::value('mp_required', '0') === '1',
        ];
    }

    public static function putSecret(string $key, ?string $value): void
    {
        Setting::put($key, $value === null || $value === '' ? null : Crypt::encryptString($value));
    }

    public static function secret(string $key): ?string
    {
        $stored = Setting::value($key);

        if ($stored === null) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /** Dónde "está" el reloj: en la zona horaria de la app. */
    protected static function now(?CarbonInterface $at): CarbonImmutable
    {
        return $at ? CarbonImmutable::instance($at)->setTimezone(config('app.timezone')) : CarbonImmutable::now(config('app.timezone'));
    }

    /**
     * El horario que cubre ese momento, si hay uno: el de hoy o el que empezó anoche y cruza la medianoche.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    protected static function currentWindow(?CarbonInterface $at): ?array
    {
        $at = self::now($at);

        foreach ([$at->startOfDay(), $at->startOfDay()->subDay()] as $day) {
            $window = self::windowOn($day);

            if ($window && $at->greaterThanOrEqualTo($window['start']) && $at->lessThan($window['end'])) {
                return $window;
            }
        }

        return null;
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable}|null */
    protected static function windowOn(CarbonImmutable $day): ?array
    {
        $row = self::schedule()[self::dayKey($day)];

        if (! $row['open']) {
            return null;
        }

        $start = $day->setTimeFromTimeString($row['from']);
        $end = $day->setTimeFromTimeString($row['to']);

        return ['start' => $start, 'end' => $end->lessThanOrEqualTo($start) ? $end->addDay() : $end];
    }

    public static function nextOpening(?CarbonInterface $at = null): ?CarbonImmutable
    {
        $at = self::now($at);

        foreach (range(0, 7) as $offset) {
            $window = self::windowOn($at->startOfDay()->addDays($offset));

            if ($window && $window['start']->greaterThan($at)) {
                return $window['start'];
            }
        }

        return null;
    }

    protected static function dayKey(CarbonInterface $day): string
    {
        return array_keys(self::DAYS)[$day->dayOfWeekIso - 1];
    }

    protected static function consecutive(?string $previous, string $day): bool
    {
        $days = array_keys(self::DAYS);

        return $previous !== null && array_search($day, $days, true) === array_search($previous, $days, true) + 1;
    }
}
