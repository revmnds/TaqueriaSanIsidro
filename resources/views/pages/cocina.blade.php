<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\BusinessDay;
use App\Support\OrderFlow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Vista para una tablet en la plancha: pedidos por preparar en letra grande.
 * "Listo" lo marca como preparado; la entrega se confirma en Pedidos.
 */
new #[Layout('layouts::kitchen')] #[Title('Cocina')] class extends Component
{
    /** Minutos que se avisa de un pedido cancelado o cambiado. */
    public const NOTICE_MINUTES = 15;

    #[Computed]
    public function queue()
    {
        // Solo lo de hoy: lo que se recoge mañana o quedó sin cobrar de otro día no se prepara ahora.
        return Order::with('items')->pending()->dueBetween(BusinessDay::startOfToday(), BusinessDay::startOfToday()->addDay())
            ->whereNull('ready_at')->whereNull('dispatched_at')->whereNull('delivered_at')
            ->orderByRaw('COALESCE(pickup_at, sold_at)')
            ->get();
    }

    /** Cuánto hay que preparar de cada producto entre todos los pedidos de la fila: "14 Taco de bistec". */
    #[Computed]
    public function allDay()
    {
        return $this->queue->flatMap->items
            ->groupBy('product_name')
            ->map(fn ($items) => $items->sum('quantity'))
            ->sortDesc();
    }

    #[Computed]
    public function ready()
    {
        return Order::pending(includeReadyHere: true)->dueBetween(BusinessDay::startOfToday(), BusinessDay::startOfToday()->addDay())->whereNotNull('ready_at')->whereNull('delivered_at')->orderByDesc('ready_at')->limit(8)->get();
    }

    /**
     * Cancelados en los últimos minutos que la cocina tenía por preparar: se avisan para que no los hagan.
     * Las correcciones de cobro no cuentan: el pedido vuelve con su mismo número.
     */
    #[Computed]
    public function cancelled()
    {
        return Order::where('status', OrderStatus::Cancelled)
            ->accepted()
            ->where('cancelled_at', '>=', now()->subMinutes(self::NOTICE_MINUTES))
            ->where(fn ($q) => $q->whereNull('cancel_reason')->orWhere('cancel_reason', '!=', Order::CORRECTION))
            ->whereNull('ready_at')->whereNull('delivered_at')
            ->dueBetween(BusinessDay::startOfToday(), BusinessDay::startOfToday()->addDay())
            ->orderByDesc('cancelled_at')
            ->get();
    }

    public function markReady(int $id, ?int $expectedVersion = null): void
    {
        $order = Order::find($id);

        if (! OrderFlow::markReady($id, $expectedVersion)) {
            $this->dispatch('toast', message: 'El pedido cambió y ya no se puede marcar listo. Recarga la lista.', type: 'error');

            return;
        }
        unset($this->queue, $this->ready);
        $this->dispatch('toast', message: '#'.$order->daily_number.' listo');
    }

    public function unready(int $id): void
    {
        OrderFlow::unready($id);
        unset($this->queue, $this->ready);
    }
};
?>

{{--
    Un pedido nuevo suena y su tarjeta parpadea: en la plancha nadie mira la tablet todo el tiempo.
    El navegador solo deja sonar después de que alguien toca la pantalla (unlock).
--}}
<div @orders-changed.window="$wire.$refresh()"
     x-data="{
        now: Date.now(),
        stopFallback: null,
        destroy() { this.stopFallback?.() },
        lock: null,
        ready: false,
        audio: null,
        audioReady: false,
        sound: (() => { try { return localStorage.getItem('cocina:sonido') !== 'no' } catch (e) { return true } })(),
        unlock() {
            try {
                this.audio ??= new AudioContext();
                this.audio.resume().then(() => this.audioReady = this.audio.state === 'running');
            } catch (e) {}
        },
        toggleSound() {
            this.sound = ! this.audioReady || ! this.sound;
            try { localStorage.setItem('cocina:sonido', this.sound ? 'si' : 'no') } catch (e) {}
            this.unlock();
            this.$nextTick(() => this.beep());
        },
        beep() {
            if (! this.sound || ! this.audio || this.audio.state !== 'running') return;
            [0, 0.28].forEach((at) => {
                const start = this.audio.currentTime + at, tone = this.audio.createOscillator(), volume = this.audio.createGain();
                tone.frequency.value = 880;
                volume.gain.setValueAtTime(0.3, start);
                volume.gain.exponentialRampToValueAtTime(0.001, start + 0.22);
                tone.connect(volume).connect(this.audio.destination);
                tone.start(start);
                tone.stop(start + 0.22);
            });
        },
     }"
     x-init="
        setInterval(() => now = Date.now(), 15000);
        stopFallback = window.realtimeFallback?.(() => $wire.$refresh(), 10);
        // Mantener la pantalla encendida mientras esta vista esté abierta.
        const keep = async () => { try { lock = await navigator.wakeLock?.request('screen') } catch (e) {} };
        keep(); document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && keep());
        // Las tarjetas que ya estaban al abrir no avisan.
        setTimeout(() => ready = true, 1000);
     "
     @pointerdown.window="unlock()"
     class="flex min-h-dvh flex-col">
    <header class="flex items-center justify-between gap-2 px-5 pt-[max(1rem,env(safe-area-inset-top))] pb-3 sm:gap-4">
        <div class="flex min-w-0 items-center gap-2 sm:gap-3">
            <a href="{{ route('pedidos') }}" class="grid size-11 place-items-center rounded-xl bg-white/10 hover:bg-white/15" aria-label="Volver a Pedidos" data-tour="cocina-volver"><x-icon name="left" /></a>
            <h1 class="font-display text-3xl font-bold sm:text-4xl">Cocina</h1>
            <button type="button" data-tour-start="cocina" class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-full border border-white/15 px-3 text-sm font-medium text-white/75 hover:bg-white/10 hover:text-white max-sm:hidden"
                    aria-label="Tutorial de esta pantalla" title="Tutorial de esta pantalla"><x-icon name="help" class="size-4" /> Tutorial</button>
            <span class="num shrink-0 rounded-full bg-[#ffc233] px-3 py-0.5 text-2xl font-bold text-[#33200f]">{{ $this->queue->count() }}</span>
        </div>
        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            <button type="button" @pointerdown.stop @click="toggleSound()" class="inline-flex h-11 items-center gap-2 rounded-xl bg-white/10 px-3 text-sm font-semibold hover:bg-white/15"
                    :aria-pressed="sound && audioReady" :aria-label="sound && audioReady ? 'Apagar sonido de pedidos nuevos' : 'Prender sonido de pedidos nuevos'">
                <span x-show="sound"><x-icon name="bell" class="size-5" /></span>
                <span x-show="! sound" x-cloak><x-icon name="bell-off" class="size-5" /></span>
                {{-- En celulares angostos solo el ícono: el texto empujaba la hora fuera de la pantalla. --}}
                <span class="max-sm:hidden" x-text="sound ? (audioReady ? 'Sonido' : 'Toca para activar el sonido') : 'Sin sonido'">Sonido</span>
            </button>
            <span class="num text-2xl font-bold text-white/70 sm:text-3xl" x-text="new Date(now).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' })"></span>
        </div>
    </header>

    <main class="flex-1 px-5 pb-28">
        {{-- "Enterado" lo quita solo en esta tablet; el aviso se va solo a los pocos minutos. --}}
        @if ($this->cancelled->isNotEmpty())
            <div x-data="{ seen: (() => { try { return JSON.parse(localStorage.getItem('cocina:enterado') || '[]') } catch (e) { return [] } })() }" class="mb-4 space-y-2">
                @foreach ($this->cancelled as $order)
                    <div wire:key="kc-{{ $order->id }}" x-show="! seen.includes({{ $order->id }})" x-init="if (ready) beep()"
                         class="flex items-center gap-4 rounded-2xl bg-[#e8392c] px-4 py-3 text-white" role="alert">
                        <span class="num grid size-12 shrink-0 place-items-center rounded-xl bg-white/20 text-2xl font-bold">{{ $order->daily_number }}</span>
                        <p class="min-w-0 flex-1 text-xl leading-tight font-bold">CANCELADO{{ $order->label ? ': '.$order->label : '' }} <span class="block text-base font-semibold text-white/85">No lo prepares.</span></p>
                        <button type="button" class="min-h-12 shrink-0 rounded-xl bg-white/20 px-4 text-base font-bold hover:bg-white/30"
                                @click="seen.push({{ $order->id }}); try { localStorage.setItem('cocina:enterado', JSON.stringify(seen.slice(-50))) } catch (e) {}">Enterado</button>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($this->queue->count() > 1)
            <div class="mb-4 flex flex-wrap items-center gap-2" aria-label="Por preparar en total">
                <span class="text-sm font-semibold text-white/60">Por preparar:</span>
                @foreach ($this->allDay as $name => $quantity)
                    <span class="rounded-xl bg-white/10 px-3 py-1.5 text-lg font-semibold"><span class="num text-[#ffc233]">{{ $quantity }}</span> {{ $name }}</span>
                @endforeach
            </div>
        @endif

        @if ($this->queue->isEmpty())
            <div class="grid h-[60dvh] place-items-center text-center">
                <div>
                    <x-icon name="check" class="mx-auto size-16 text-[#4cc07a]" />
                    <p class="mt-3 font-display text-4xl font-bold">Todo al día</p>
                    <p class="mt-1 text-lg text-white/60">Los pedidos nuevos aparecen aquí solos.</p>
                </div>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->queue as $order)
                    @php
                        $since = $order->sold_at->getTimestampMs();
                        $pickup = $order->pickup_at?->getTimestampMs();
                    @endphp
                    @php $changedRecently = $order->changed_at?->gt(now()->subMinutes($this::NOTICE_MINUTES)); @endphp
                    {{-- Si el pedido cambia, su clave cambia: la tarjeta se vuelve a crear, suena y parpadea. --}}
                    <article wire:key="k-{{ $order->id }}-{{ $order->changed_at?->timestamp }}" x-init="if (ready) { beep(); $el.classList.add('kitchen-new') }"
                             class="flex flex-col rounded-3xl bg-[#231c17] ring-1 ring-white/10" @if ($loop->first) data-tour="cocina-pedido" @endif>
                        <div class="flex items-start gap-3 p-4 pb-3">
                            <span class="num grid size-14 shrink-0 place-items-center rounded-2xl bg-[#ffc233] text-3xl font-bold text-[#33200f]">{{ $order->daily_number }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-display text-3xl leading-tight font-bold">{{ $order->label ?: ($order->serviceLabel() ?? 'Pedido') }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-2 text-base">
                                    @if ($changedRecently)
                                        <span class="rounded-md bg-[#ffc233] px-2 py-0.5 font-bold text-[#33200f]">CAMBIÓ</span>
                                    @endif
                                    @if ($order->serviceLabel() && ($order->label || $order->isDelivery()))
                                        <span @class(['rounded-md px-2 py-0.5 font-semibold', 'bg-[#f08a3c] text-[#33200f]' => $order->isDelivery(), 'bg-white/12' => ! $order->isDelivery()])>{{ $order->serviceLabel() }}</span>
                                    @endif
                                    @if ($pickup)
                                        <span class="rounded-md bg-[#f08a3c] px-2 py-0.5 font-semibold text-[#33200f]">{{ $order->scheduleVerb() }} {{ $order->pickup_at->format('g:i a') }}</span>
                                    @else
                                        <span :class="(now - {{ $since }}) > 15 * 60000 ? 'font-bold text-[#ff6b5e]' : 'text-white/60'"
                                              x-text="(() => { const m = Math.max(0, Math.floor((now - {{ $since }}) / 60000)); return m < 1 ? 'recién' : (m < 60 ? 'hace ' + m + ' min' : 'hace ' + Math.floor(m / 60) + ' h ' + (m % 60) + ' min') })()"></span>
                                    @endif
                                </p>
                            </div>
                        </div>
                        <ul class="flex-1 space-y-3 border-t border-white/10 px-4 py-4">
                            @foreach ($order->items as $item)
                                <li class="flex gap-3">
                                    <span class="num w-10 shrink-0 text-4xl leading-none font-bold text-[#ffc233]">{{ $item->quantity }}</span>
                                    <span class="min-w-0">
                                        <span class="block text-2xl leading-tight font-semibold">{{ $item->product_name }}</span>
                                        @if ($item->modifiers || $item->notes)
                                            <span class="mt-1.5 flex flex-wrap gap-1.5">
                                                @foreach ($item->choices() as $choice)
                                                    <span class="rounded-lg bg-[#ffc233] px-2 py-0.5 text-lg font-bold text-[#33200f]">{{ $choice['name'] }}</span>
                                                @endforeach
                                                @foreach ($item->withoutNames() as $name)
                                                    <span class="rounded-lg bg-[#e8392c] px-2 py-0.5 text-lg font-bold">SIN {{ mb_strtoupper($name) }}</span>
                                                @endforeach
                                                @foreach ($item->extras() as $extra)
                                                    <span class="rounded-lg bg-[#17803d] px-2 py-0.5 text-lg font-bold">+ {{ $extra['name'] }}</span>
                                                @endforeach
                                                @if ($item->notes)
                                                    <span class="text-lg text-[#ffc233] italic">"{{ $item->notes }}"</span>
                                                @endif
                                            </span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="p-3 pt-0">
                            <button type="button" wire:click="markReady({{ $order->id }}, {{ $order->version }})" @if ($loop->first) data-tour="cocina-listo" @endif class="flex min-h-16 w-full items-center justify-center gap-2 rounded-2xl bg-[#17803d] text-2xl font-bold text-white hover:bg-[#156f35] motion-safe:active:scale-[0.98]">
                                <x-icon name="check" class="size-7" /> Listo
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </main>

    @if ($this->ready->isNotEmpty())
        <footer data-tour="cocina-listos" class="fixed inset-x-0 bottom-0 flex items-center gap-2 overflow-x-auto border-t border-white/10 bg-[#120e0b]/95 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
            <span class="shrink-0 text-sm font-semibold text-white/60">Listos:</span>
            @foreach ($this->ready as $order)
                <button type="button" wire:click="unready({{ $order->id }})" title="Regresar a preparación"
                        class="flex shrink-0 items-center gap-2 rounded-xl bg-white/10 px-3 py-2 hover:bg-white/15">
                    <span class="num text-xl font-bold text-[#ffc233]">{{ $order->daily_number }}</span>
                    <span class="max-w-40 truncate font-semibold">{{ $order->label }}</span>
                    <x-icon name="undo" class="size-4 text-white/50" />
                </button>
            @endforeach
        </footer>
    @endif
</div>
