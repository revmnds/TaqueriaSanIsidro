<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Branding;
use App\Support\OnlineOrder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Tu pedido')] class extends Component
{
    /** El enlace secreto del pedido: quien lo tiene puede verlo. */
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $order = Order::where('public_token', $token)->where('source', 'online')->first();
        abort_if(! $order || $order->isTrackingExpired(), 404);
    }

    #[Computed]
    public function order(): Order
    {
        OnlineOrder::expireStale();

        $order = Order::with('items')->where('public_token', $this->token)->where('source', 'online')->firstOrFail();
        abort_if($order->isTrackingExpired(), 404);

        return $order;
    }

    /** En qué va el pedido, dicho para el cliente (el mismo texto que usa el bot de Telegram). */
    #[Computed]
    public function stage(): array
    {
        return $this->order->customerStage();
    }

    /** Los pasos que ve el cliente, en orden. */
    #[Computed]
    public function steps(): array
    {
        return ['Recibido', 'Aceptado', 'Preparando', $this->order->isDelivery() ? 'Listo / en camino' : 'Listo para recoger', 'Entregado'];
    }

    public function cancel(): void
    {
        if (! OnlineOrder::cancelByCustomer($this->order)) {
            $this->dispatch('toast', message: 'Tu pedido ya fue atendido y no se puede cancelar desde aquí.', type: 'error');
        }

        unset($this->order, $this->stage);
    }
};
?>

@php
    $order = $this->order;
    $stage = $this->stage;
    $paymentNote = $order->status === \App\Enums\OrderStatus::Paid
        ? 'Tu pedido ya quedó pagado.'
        : ($order->isDelivery() ? 'Pagas cuando te lo entregamos.' : 'Pagas cuando recoges tu pedido.');
@endphp

<div class="mx-auto min-h-dvh max-w-xl pb-10"
     x-data="{ stopFallback: null, destroy() { this.stopFallback?.() }, listen() { if (! window.Echo) return; window.Echo.channel('order.{{ $token }}').listen('.updated', () => $wire.$refresh()); } }"
     x-init="listen(); stopFallback = window.realtimeFallback?.(() => $wire.$refresh(), 10)">
    <header class="bg-brand flex flex-col items-center rounded-b-3xl px-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-5 text-center">
        <x-logo class="size-28 shadow-lg ring-4 ring-white/80" :res="512" />
        <p class="mt-3 font-display text-[1.75rem] leading-tight">{{ Branding::name() }}</p>
        <p class="text-sm">Pedido de {{ $order->label }}</p>
    </header>

    <main class="space-y-5 px-4 pt-5">
        <section @class([
            'card space-y-2 !p-5',
            '!bg-limon-soft' => $stage['tone'] === 'wait',
            '!bg-salsa-soft' => $stage['tone'] === 'good',
            '!bg-chile-soft' => $stage['tone'] === 'bad',
        ]) role="status" aria-live="polite">
            <h1 class="font-display text-[1.75rem] leading-tight" data-stage="{{ $stage['key'] }}">{{ $stage['title'] }}</h1>
            <p class="text-[15px]">{{ $stage['text'] }}</p>
        </section>

        @if ($stage['step'] > 0)
            <ol class="grid grid-cols-5 gap-1.5" aria-label="Avance del pedido">
                @foreach ($this->steps as $i => $label)
                    @php $number = $i + 1; @endphp
                    <li class="space-y-1.5 text-center">
                        <span @class(['block h-1.5 rounded-full', 'bg-ink' => $number <= $stage['step'], 'bg-line' => $number > $stage['step']])></span>
                        <span @class(['block text-[11px] leading-tight', 'font-bold text-ink' => $number === $stage['step'], 'text-muted' => $number !== $stage['step']])>{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        <section class="card space-y-3">
            <h2 class="section-title">Tu pedido</h2>
            <ul class="divide-y divide-line">
                @foreach ($order->items as $item)
                    <li class="flex gap-3 py-2.5">
                        <span class="num w-6 shrink-0 font-bold">{{ $item->quantity }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $item->product_name }}</p>
                            @php
                                $details = array_filter([
                                    ...collect($item->choices())->pluck('name')->all(),
                                    ...array_map(fn ($n) => 'sin '.mb_strtolower($n), $item->withoutNames()),
                                    ...collect($item->extras())->pluck('name')->all(),
                                    $item->notes ? '“'.$item->notes.'”' : null,
                                ]);
                            @endphp
                            @if ($details)
                                <p class="text-sm text-muted">{{ implode(', ', $details) }}</p>
                            @endif
                        </div>
                        <span class="num font-semibold">{{ money((float) $item->subtotal) }}</span>
                    </li>
                @endforeach
            </ul>
            <dl class="space-y-1 border-t border-line pt-3 text-[15px]">
                @if ((float) $order->packaging_fee > 0)
                    <div class="flex justify-between"><dt class="text-muted">Empaque</dt><dd class="num">{{ money($order->packaging_fee) }}</dd></div>
                @endif
                @if ((float) $order->delivery_fee > 0)
                    <div class="flex justify-between"><dt class="text-muted">Envío</dt><dd class="num">{{ money($order->delivery_fee) }}</dd></div>
                @endif
                <div class="flex justify-between text-xl font-bold"><dt>Total</dt><dd class="num">{{ money($order->total) }}</dd></div>
            </dl>
            <p class="hint">{{ $paymentNote }}</p>
        </section>

        <section class="card space-y-1 text-[15px]">
            <p><span class="text-muted">{{ $order->isDelivery() ? 'A domicilio' : 'Paso a recoger' }}</span></p>
            @if ($order->isDelivery())
                <p class="font-semibold">{{ $order->delivery_address }}</p>
                @if ($order->delivery_notes)
                    <p class="text-muted">{{ $order->delivery_notes }}</p>
                @endif
            @endif
            <p class="text-muted">Teléfono: {{ $order->customer_phone }}</p>
            @if ($order->notes)
                <p class="text-muted">Nota: {{ $order->notes }}</p>
            @endif
        </section>

        @if ($order->status === OrderStatus::Requested)
            <button type="button" class="btn w-full" @click="if (await $store.confirm.ask({ title: '¿Cancelar tu pedido?', message: 'Todavía no lo han aceptado, así que no pasa nada.', action: 'Cancelar pedido', cancel: 'No' })) $wire.cancel()">Cancelar mi pedido</button>
        @endif

        @if (\App\Support\Telegram::enabled() && $order->status === OrderStatus::Requested || \App\Support\Telegram::enabled() && in_array($this->stage['key'], ['preparing', 'ready', 'on-the-way'], true))
            @if ($order->telegram_chat_id)
                <p class="text-center text-[15px] text-muted">Te avisamos de cada paso por Telegram.</p>
            @elseif ($link = \App\Support\Telegram::orderLink($token))
                <a href="{{ $link }}" target="_blank" rel="noopener" class="btn w-full" data-telegram-link>Recibir avisos por Telegram</a>
            @endif
        @endif

        @if ($phone = \App\Support\Store::phone())
            <p class="text-center text-[15px]">¿Dudas? <a href="tel:{{ $phone }}" class="font-semibold underline">Llámanos al {{ substr($phone, 0, 3).' '.substr($phone, 3, 3).' '.substr($phone, 6) }}</a></p>
        @endif

        <p class="text-center text-sm text-muted">Guarda este enlace para volver a ver cómo va tu pedido.</p>
        <a href="{{ route('pedir') }}" class="btn w-full">Hacer otro pedido</a>
    </main>
</div>
