<?php

use App\Enums\OrderStatus;
use App\Models\CashClose;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Corte de caja')] class extends Component
{
    public string $date = '';

    public ?string $opening = '0';

    public ?string $counted = null;

    public ?string $notes = null;

    /** Lo que se queda en la caja al cerrar: es el fondo inicial del día siguiente. Vacío, el mismo fondo de hoy. */
    public ?string $kept = null;

    /** Totales del día; el navegador los usa para calcular al instante. */
    public float $cashSales = 0;

    public float $otherSales = 0;

    public float $cashExpenses = 0;

    /** Entradas menos retiros de efectivo del día. */
    public float $movementsNet = 0;

    public float $tips = 0;

    /** Propinas de ventas pagadas en efectivo o mixto: son las que pueden estar en la caja. */
    public float $cashTips = 0;

    /** Formulario de movimiento de caja. */
    public string $moveType = 'out';

    public ?string $moveAmount = null;

    public ?string $moveReason = null;

    public function mount(): void
    {
        $this->date = BusinessDay::today()->toDateString();
        $this->loadClose();
    }

    public function updatedDate(): void
    {
        $this->loadClose();
    }

    /** Si ya hay corte guardado para el día, lo muestra; si no, hereda el fondo del anterior. */
    protected function loadClose(): void
    {
        $this->loadTotals();
        unset($this->saved);

        $saved = CashClose::whereDate('closed_on', $this->day())->first();

        if ($saved) {
            $this->opening = $this->plain($saved->opening);
            $this->counted = $this->plain($saved->counted);
            $this->kept = $saved->kept !== null ? $this->plain($saved->kept) : null;
            $this->notes = $saved->notes;

            return;
        }

        // El fondo es lo que se dejó en la caja al cerrar el día anterior; los cortes viejos no lo tienen y usan todo lo contado.
        $previous = CashClose::whereDate('closed_on', '<', $this->day())->orderByDesc('closed_on')->first();
        $this->opening = $this->plain($previous?->kept ?? $previous?->counted ?? 0);
        $this->kept = null;
        $this->counted = null;
        $this->notes = null;
    }

    protected function plain(mixed $n): string
    {
        $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    protected function day(): CarbonImmutable
    {
        return BusinessDay::parseDate($this->date) ?? BusinessDay::today();
    }

    #[Computed]
    public function dayLabel(): string
    {
        $day = $this->day();

        return match (true) {
            $day->equalTo(BusinessDay::today()) => 'Hoy',
            $day->equalTo(BusinessDay::today()->subDay()) => 'Ayer',
            default => ucfirst($day->translatedFormat('l')),
        };
    }

    #[Computed]
    public function saved(): ?CashClose
    {
        return CashClose::whereDate('closed_on', $this->day())->first();
    }

    protected function loadTotals(): void
    {
        $day = $this->day();
        $range = [BusinessDay::start($day), BusinessDay::end($day)];

        // Pagos de órdenes pagadas, por el día en que se cobraron.
        $payments = OrderPayment::whereBetween('created_at', $range)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid));

        $this->cashSales = (float) (clone $payments)->where('method', 'cash')->sum('amount');
        $this->otherSales = (float) (clone $payments)->where('method', '!=', 'cash')->sum('amount');
        $this->tips = (float) Order::where('status', OrderStatus::Paid)->whereBetween('paid_at', $range)->sum('tip');
        $this->cashTips = (float) Order::where('status', OrderStatus::Paid)->whereBetween('paid_at', $range)->whereIn('payment_method', ['cash', 'mixed'])->sum('tip');
        $this->cashExpenses = (float) Expense::whereDate('spent_on', $day)->where('from_cash', true)->sum('total');
        $this->movementsNet = (float) CashMovement::whereBetween('moved_at', $range)->get()->sum(fn ($m) => $m->signed());
    }

    /** Lo llama el poll: si se vende en otro dispositivo, el esperado se mantiene al día. */
    public function refreshTotals(): void
    {
        $this->loadTotals();
        unset($this->movements);
    }

    #[Computed]
    public function movements()
    {
        $day = $this->day();

        return CashMovement::whereBetween('moved_at', [BusinessDay::start($day), BusinessDay::end($day)])->orderBy('moved_at')->get();
    }

    protected function validateDate(): void
    {
        $this->validate(['date' => ['required', function ($attribute, $value, $fail) {
            if (! BusinessDay::parseDate($value)) {
                $fail('Elige una fecha de hoy o anterior.');
            }
        }]], ['date.required' => 'Elige una fecha válida.'], ['date' => 'fecha']);
    }

    public function addMovement(): void
    {
        $this->validateDate();

        $this->validate([
            'moveType' => ['required', 'in:in,out'],
            'moveAmount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'moveReason' => ['nullable', 'string', 'max:120'],
        ], attributes: ['moveAmount' => 'monto', 'moveReason' => 'motivo']);

        $day = $this->day();
        CashMovement::create([
            'user_id' => auth()->id(),
            'type' => $this->moveType,
            'amount' => $this->moveAmount,
            'reason' => $this->moveReason ?: null,
            'moved_at' => $day->equalTo(BusinessDay::today()) ? now() : BusinessDay::end($day)->subMinute(),
        ]);

        $this->reset('moveAmount', 'moveReason');
        $this->loadTotals();
        unset($this->movements);
        $this->dispatch('toast', message: 'Movimiento registrado');
        $this->dispatch('movement-saved');
    }

    public function deleteMovement(int $id): void
    {
        $this->validateDate();

        CashMovement::whereKey($id)->whereBetween('moved_at', [BusinessDay::start($this->day()), BusinessDay::end($this->day())])->delete();
        $this->loadTotals();
        unset($this->movements);
    }

    #[Computed]
    public function expected(): float
    {
        return round((is_numeric($this->opening) ? (float) $this->opening : 0) + $this->cashSales + $this->movementsNet - $this->cashExpenses, 2);
    }

    #[Computed]
    public function difference(): ?float
    {
        return is_numeric($this->counted) ? round((float) $this->counted - $this->expected, 2) : null;
    }

    /**
     * Ventas cobradas y luego canceladas ese día. Las que se corrigieron y se volvieron a cobrar no cuentan;
     * una corrección que se dejó a medias sí, porque ese dinero ya no está en las ventas.
     */
    #[Computed]
    public function cancelled()
    {
        return Order::where('status', OrderStatus::Cancelled)
            ->accepted()
            ->whereNotNull('paid_at')
            ->countedBetween(BusinessDay::start($this->day()), BusinessDay::end($this->day()))
            ->get()
            ->reject(fn (Order $order) => $order->cancel_reason === Order::CORRECTION && $order->wasCorrected())
            ->values();
    }

    /** Los cortes de antes del día que se está viendo. */
    #[Computed]
    public function history()
    {
        return CashClose::whereDate('closed_on', '<', $this->day())->orderByDesc('closed_on')->limit(7)->get();
    }

    /** El corte guardado ya no coincide: después hubo ventas, cancelaciones, gastos o movimientos. */
    #[Computed]
    public function savedIsStale(): bool
    {
        return $this->saved && abs((float) $this->saved->expected - $this->expected) >= 0.005;
    }

    public function save(): void
    {
        $this->validateDate();

        $this->validate([
            'opening' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'counted' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'kept' => ['nullable', 'numeric', 'min:0', 'lte:counted'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'kept.lte' => 'No puede quedarse más de lo que contaste.',
        ], ['opening' => 'fondo inicial', 'counted' => 'efectivo contado', 'kept' => 'fondo para mañana']);

        // Los totales se cargaron al abrir la página: se recalculan para guardar el esperado de este momento.
        $this->loadTotals();
        unset($this->expected, $this->difference);

        CashClose::updateOrCreate(['closed_on' => $this->day()->toDateString()], [
            'user_id' => auth()->id(),
            'opening' => $this->opening,
            'expected' => $this->expected,
            'counted' => $this->counted,
            'kept' => is_numeric($this->kept) ? $this->kept : min((float) $this->opening, (float) $this->counted),
            'difference' => $this->difference,
            'notes' => $this->notes ?: null,
        ]);

        unset($this->history, $this->saved);
        $this->dispatch('toast', message: 'Corte guardado');
    }
};
?>

<div wire:poll.60s.visible="refreshTotals" @orders-changed.window="$wire.refreshTotals()" x-data="{
        get expected() { return Math.round(((Number($wire.opening) || 0) + $wire.cashSales + $wire.movementsNet - $wire.cashExpenses) * 100) / 100 },
        moveOpen: false,
        moveError: '',
        get takeHome() {
            const counted = Number($wire.counted);
            const kept = $wire.kept === null || $wire.kept === '' ? Math.min(Number($wire.opening) || 0, counted) : Number($wire.kept);
            if ($wire.counted === null || $wire.counted === '' || isNaN(counted) || kept > counted) return null;
            return Math.round((counted - kept) * 100) / 100;
        },
        get diff() {
            if ($wire.counted === null || $wire.counted === '' || isNaN($wire.counted)) return null;
            return Math.round((Number($wire.counted) - this.expected) * 100) / 100;
        },
        fmt(n) {
            n = Math.round(n * 100) / 100;
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
        },
     }">
    <x-page-header title="Corte de caja" tour="corte">
        <x-date-nav :max="BusinessDay::today()->toDateString()" />
    </x-page-header>

    <div class="mx-auto max-w-xl space-y-4">
        @error('date') <p class="error" role="alert">{{ $message }}</p> @enderror
        <section class="card !p-0">
            <dl class="divide-y divide-line">
                <div class="flex items-center justify-between gap-4 px-4 py-3" data-tour="corte-fondo">
                    <dt>
                        <label for="opening" class="font-semibold">Fondo inicial</label>
                        <p class="text-sm text-muted">Con lo que abriste la caja</p>
                    </dt>
                    <dd class="w-36"><x-money-input id="opening" wire:model="opening" class="!min-h-11 text-right !text-lg" /></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-muted">Cobrado en efectivo @if ($cashTips > 0)<span class="block text-xs">incluye propinas</span>@endif</dt>
                    <dd class="num text-xl font-bold" wire:loading.class="animate-pulse opacity-40" wire:target="date">{{ $cashSales > 0 ? '+' : '' }}{{ money($cashSales) }}</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-muted">Gastos pagados de la caja</dt>
                    <dd class="num text-xl font-bold" wire:loading.class="animate-pulse opacity-40" wire:target="date">{{ $cashExpenses > 0 ? '−'.money($cashExpenses) : money(0) }}</dd>
                </div>
                @if ($movementsNet != 0)
                    <div class="flex items-center justify-between px-4 py-3">
                        <dt class="text-muted">Entradas y retiros</dt>
                        <dd class="num text-xl font-bold">{{ $movementsNet > 0 ? '+' : '−' }}{{ money(abs($movementsNet)) }}</dd>
                    </div>
                @endif
                <div class="flex items-end justify-between bg-sunken/60 px-4 py-4" data-tour="corte-esperado">
                    <dt class="font-semibold">Debe haber en caja</dt>
                    <dd class="num text-[2.5rem] leading-none font-bold" x-text="fmt(expected)" wire:loading.class="animate-pulse opacity-40" wire:target="date">{{ money($this->expected) }}</dd>
                </div>
            </dl>
            @error('opening') <p class="error px-4 pb-3">{{ $message }}</p> @enderror
        </section>

        <p x-show="expected < 0" x-cloak role="alert" class="flex gap-2 rounded-2xl bg-chile-soft px-4 py-3 text-sm text-chile">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>La caja no puede tener menos de $0. Revisa el fondo inicial y los gastos marcados «De la caja» en <a href="{{ route('gastos') }}" wire:navigate class="font-semibold underline">Gastos</a>.</span>
        </p>

        {{-- Lo pendiente es de ahora: no tiene caso avisarlo al revisar el corte de otro día. --}}
        @php $unpaid = $this->dayLabel === 'Hoy' ? \App\Models\Order::where('status', \App\Enums\OrderStatus::Open)->get(['total']) : collect(); @endphp
        @if ($unpaid->isNotEmpty())
            <a href="{{ route('pedidos') }}" wire:navigate class="flex items-center gap-3 rounded-2xl bg-limon-soft px-4 py-3 text-sm">
                <x-icon name="alert" class="size-5 shrink-0" />
                <span class="flex-1">Hay <span class="font-semibold">{{ $unpaid->count() }} {{ $unpaid->count() === 1 ? 'pedido' : 'pedidos' }} sin cobrar</span> por {{ money($unpaid->sum('total')) }}. Cóbralos antes de cerrar.</span>
                <x-icon name="right" class="size-4" />
            </a>
        @endif

        @if ($otherSales > 0)
            <p class="flex gap-2 px-1 text-sm text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>Además se cobraron {{ money($otherSales) }} por transferencia o tarjeta. Ese dinero no entra a la caja.</span>
            </p>
        @endif

        @if ($this->cancelled->isNotEmpty())
            <p class="flex gap-2 px-1 text-sm text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>Se {{ $this->cancelled->count() === 1 ? 'canceló 1 venta' : 'cancelaron '.$this->cancelled->count().' ventas' }} por <span class="font-semibold text-ink">{{ money($this->cancelled->sum('total')) }}</span>. Ya no cuentan en el efectivo. <a href="{{ route('ventas') }}" wire:navigate class="font-semibold underline">Ver en Ventas</a></span>
            </p>
        @endif

        @if ($tips > 0)
            <p class="flex gap-2 px-1 text-sm text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>Propinas del día: <span class="font-semibold text-ink">{{ money($tips) }}</span>. No cuentan como venta.</span>
            </p>
        @endif

        {{-- Movimientos de caja --}}
        <section class="space-y-2" data-tour="corte-movimientos">
            <div class="flex items-center justify-between px-1">
                <h2 class="section-title">Movimientos de caja</h2>
                <div class="flex gap-2">
                    <button type="button" class="btn !min-h-9 !px-3" @click="$wire.moveType = 'out'; moveError = ''; moveOpen = true">Sacar</button>
                    <button type="button" class="btn !min-h-9 !px-3" @click="$wire.moveType = 'in'; moveError = ''; moveOpen = true">Meter</button>
                </div>
            </div>
            @if ($this->movements->isEmpty())
                <p class="px-1 text-sm text-muted">Si sacas efectivo de la caja (para pagar algo personal, depositar) o metes cambio, anótalo aquí para que el corte cuadre.</p>
            @else
                <ul class="list">
                    @foreach ($this->movements as $m)
                        <li wire:key="mv-{{ $m->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                            <span @class(['grid size-9 shrink-0 place-items-center rounded-xl', 'bg-salsa-soft text-salsa' => $m->type === 'in', 'bg-chile-soft text-chile' => $m->type === 'out'])>
                                <x-icon :name="$m->type === 'in' ? 'plus' : 'minus'" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $m->reason ?: ($m->type === 'in' ? 'Entrada de efectivo' : 'Retiro de efectivo') }}</span>
                                <span class="block text-sm text-muted">{{ $m->moved_at->format('g:i a') }}</span>
                            </span>
                            <span class="num text-xl font-bold">{{ $m->type === 'in' ? '+' : '−' }}{{ money($m->amount) }}</span>
                            <button type="button" class="btn-quiet size-10 justify-center !px-0" aria-label="Borrar movimiento"
                                    @click="if (await $store.confirm.ask({ title: '¿Borrar este movimiento?', message: @js(($m->reason ?: 'Movimiento').' por '.money($m->amount)), action: 'Borrar' })) $wire.deleteMovement({{ $m->id }})">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div wire:ignore>
            <x-sheet title="Movimiento de caja" open="moveOpen" x-text="$wire.moveType === 'out' ? 'Sacar efectivo' : 'Meter efectivo'" @movement-saved.window="moveOpen = false">
                {{-- Está dentro de wire:ignore: los errores se leen de $wire al terminar en lugar de @error. --}}
                <form @submit.prevent="moveError = ''; await $wire.addMovement(); moveError = $wire.$errors.first('moveAmount') || $wire.$errors.first('moveReason') || $wire.$errors.first('moveType')" id="move-form" class="space-y-4">
                    <div class="segmented" role="group" aria-label="Tipo de movimiento">
                        <button type="button" @click="$wire.moveType = 'out'" :aria-pressed="$wire.moveType === 'out'">Sacar</button>
                        <button type="button" @click="$wire.moveType = 'in'" :aria-pressed="$wire.moveType === 'in'">Meter</button>
                    </div>
                    <div>
                        <label class="label" for="move-amount">Monto</label>
                        <x-money-input id="move-amount" x-model="$wire.moveAmount" placeholder="0" class="!min-h-14 !text-2xl" />
                    </div>
                    <div>
                        <label class="label" for="move-reason">Motivo <span class="font-normal text-muted">(opcional)</span></label>
                        <input id="move-reason" type="text" x-model="$wire.moveReason" maxlength="120" class="input" placeholder="Ej. depósito al banco, cambio para la caja">
                    </div>
                    <p class="error" x-show="moveError" x-text="moveError" x-cloak></p>
                </form>
                <x-slot:footer>
                    <button type="submit" form="move-form" class="btn-primary btn-lg w-full" :disabled="! (Number($wire.moveAmount) > 0)">Guardar movimiento</button>
                </x-slot:footer>
            </x-sheet>
        </div>

        <section class="card space-y-4 !p-5">
            <div data-tour="corte-contado">
                <label class="label" for="counted">Efectivo contado</label>
                <x-money-input id="counted" wire:model="counted" placeholder="0" class="!min-h-14 !text-2xl" />
                <p class="hint">Cuenta billetes y monedas al cerrar.</p>
                @error('counted') <p class="error">{{ $message }}</p> @enderror

                {{-- Arqueo por billetes: se cuenta cada uno y el total pasa solo a Efectivo contado. --}}
                <div class="mt-2" x-data="{
                        open: false,
                        used: false,
                        bills: { 500: '', 200: '', 100: '', 50: '', 20: '' },
                        coins: '',
                        get sum() { return Object.entries(this.bills).reduce((t, [value, n]) => t + Number(value) * (Number(n) || 0), 0) + (Number(this.coins) || 0) },
                     }"
                     @input="used = true"
                     x-effect="if (open && used) $wire.counted = String(Math.round(sum * 100) / 100)">
                    <button type="button" class="btn-quiet -ml-2.5" @click="open = ! open" :aria-expanded="open">
                        <x-icon name="banknote" class="size-4" /> Contar por billetes
                    </button>
                    <div x-show="open" x-collapse x-cloak class="mt-2 space-y-2 rounded-xl bg-sunken/60 p-3">
                        <template x-for="value in [500, 200, 100, 50, 20]" :key="value">
                            <label class="flex items-center gap-3">
                                <span class="w-28 text-sm" x-text="'Billetes de $' + value"></span>
                                <input type="number" inputmode="numeric" min="0" x-model="bills[value]" class="input !min-h-10 w-20 text-center" placeholder="0" :aria-label="'Billetes de ' + value">
                                <span class="num ml-auto text-base" x-text="fmt(value * (Number(bills[value]) || 0))"></span>
                            </label>
                        </template>
                        <label class="flex items-center gap-3">
                            <span class="w-28 text-sm">Monedas</span>
                            <span class="w-32"><x-money-input x-model="coins" placeholder="0" class="!min-h-10 !text-base" aria-label="Total en monedas" /></span>
                        </label>
                        <p class="flex items-center justify-between border-t border-line pt-2 text-sm font-semibold">Total contado <span class="num text-xl" x-text="fmt(sum)"></span></p>
                    </div>
                </div>
            </div>

            <div x-show="diff !== null" x-cloak class="flex items-center justify-between gap-3 rounded-xl px-4 py-3.5"
                 :class="Math.abs(diff) < 0.005 ? 'bg-salsa-soft text-salsa' : (diff > 0 ? 'bg-limon-soft text-ink' : 'bg-chile-soft text-chile')">
                <span class="flex items-center gap-2 text-sm font-bold">
                    <span x-show="Math.abs(diff) < 0.005"><x-icon name="check" class="size-5" /></span>
                    <span x-show="Math.abs(diff) >= 0.005"><x-icon name="alert" class="size-5" /></span>
                    <span x-text="Math.abs(diff) < 0.005 ? 'La caja cuadra' : (diff > 0 ? 'Sobra dinero' : 'Falta dinero')"></span>
                </span>
                <span x-show="Math.abs(diff) >= 0.005" class="num text-[2rem] leading-none font-bold" x-text="fmt(Math.abs(diff ?? 0))"></span>
            </div>

            <div>
                <label class="label" for="kept">Se queda de fondo para mañana</label>
                <x-money-input id="kept" wire:model="kept" x-bind:placeholder="$wire.opening || '0'" />
                <p class="hint" x-show="takeHome !== null" x-cloak>Te llevas <span class="font-semibold text-ink" x-text="fmt(takeHome)"></span>. Mañana la caja empieza con el fondo.</p>
                @error('kept') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="notes">Nota <span class="font-normal text-muted">(opcional)</span></label>
                <input id="notes" type="text" wire:model="notes" class="input" maxlength="255" placeholder="Ej. se pagó el gas con efectivo de la caja">
                @error('notes') <p class="error">{{ $message }}</p> @enderror
            </div>

            <button type="button" wire:click="save" data-tour="corte-guardar" class="btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $this->saved ? 'Actualizar corte' : 'Guardar corte' }}</span>
                <span wire:loading wire:target="save">Guardando...</span>
            </button>
            @if ($this->savedIsStale)
                <p class="-mt-2 flex gap-2 rounded-xl bg-limon-soft px-3 py-2.5 text-sm">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
                    <span>Después de guardar hubo cambios: el corte guardado decía {{ money($this->saved->expected) }} y ahora debe haber {{ money($this->expected) }}. Vuelve a contar y actualízalo.</span>
                </p>
            @elseif ($this->saved)
                <p class="-mt-2 text-center text-sm text-muted">Guardado a las {{ $this->saved->updated_at->format('g:i a') }}</p>
            @endif
        </section>

        @if ($this->history->isNotEmpty())
            <section class="space-y-2 pt-2" data-tour="corte-historial">
                <h2 class="section-title px-1">Cortes anteriores</h2>
                <ul class="list">
                    @foreach ($this->history as $close)
                        @php $d = (float) $close->difference; @endphp
                        <li class="flex items-center gap-3 px-4 py-3">
                            <span class="flex-1">
                                <span class="block font-semibold">{{ ucfirst($close->closed_on->translatedFormat('l j M')) }}</span>
                                <span class="block text-sm text-muted">Contado {{ money($close->counted) }}</span>
                            </span>
                            <span @class([
                                'badge !text-sm',
                                'bg-salsa-soft text-salsa' => abs($d) < 0.005,
                                'bg-limon-soft text-ink' => $d >= 0.005,
                                'bg-chile-soft text-chile' => $d <= -0.005,
                            ])>
                                {{ abs($d) < 0.005 ? 'Cuadró' : ($d > 0 ? 'Sobró ' : 'Faltó ').money(abs($d)) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
