<?php

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Gastos')] class extends Component
{
    public string $month = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $spent_on = '';

    public string $category = 'ingredients';

    public ?int $ingredient_id = null;

    public ?string $description = null;

    public ?string $supplier = null;

    public ?string $quantity = null;

    public ?string $total = null;

    public bool $from_cash = true;

    public function mount(): void
    {
        $this->month = BusinessDay::today()->format('Y-m');
        $this->spent_on = BusinessDay::today()->toDateString();
    }

    protected function start(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        } catch (Throwable) {
            return CarbonImmutable::today()->startOfMonth();
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->start()->addMonths($by)->format('Y-m');
        unset($this->expenses);
    }

    #[Computed]
    public function monthLabel(): string
    {
        return ucfirst($this->start()->translatedFormat('F Y'));
    }

    /** Insumos activos y, al editar, el de ese gasto aunque ya esté inactivo. */
    #[Computed]
    public function ingredients()
    {
        return Ingredient::where('active', true)
            ->when($this->ingredient_id, fn ($q) => $q->orWhere('id', $this->ingredient_id))
            ->orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function suppliers()
    {
        return Expense::whereNotNull('supplier')->distinct()->orderBy('supplier')->pluck('supplier');
    }

    #[Computed]
    public function expenses()
    {
        return Expense::with('ingredient')
            ->whereDate('spent_on', '>=', $this->start())
            ->whereDate('spent_on', '<=', $this->start()->endOfMonth())
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->get();
    }

    public function edit(int $id): void
    {
        $e = Expense::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $e->id;
        $this->spent_on = $e->spent_on->toDateString();
        $this->category = $e->category->value;
        $this->ingredient_id = $e->ingredient_id;
        $this->description = $e->description;
        $this->supplier = $e->supplier;
        $this->quantity = $e->quantity !== null ? rtrim(rtrim((string) $e->quantity, '0'), '.') : null;
        $this->total = rtrim(rtrim((string) $e->total, '0'), '.');
        $this->from_cash = $e->from_cash;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'ingredient_id', 'description', 'supplier', 'quantity', 'total');
        $this->category = 'ingredients';
        $this->from_cash = true;
        $this->spent_on = BusinessDay::today()->toDateString();
        $this->resetErrorBag();
    }

    public function save(): void
    {
        if ($this->category !== 'ingredients') {
            $this->ingredient_id = null;
            $this->quantity = null;
        }

        $data = $this->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'ingredient_id' => ['nullable', 'exists:ingredients,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'quantity' => [$this->ingredient_id ? 'required' : 'nullable', 'numeric', 'min:0.0001', 'max:999999'],
            'total' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'from_cash' => ['boolean'],
        ], attributes: ['total' => 'total pagado', 'spent_on' => 'fecha', 'ingredient_id' => 'insumo']);

        // El costo por unidad se guarda con 4 decimales: una cantidad muy chica o muy grande lo dejaría en cero o no cabría.
        if ($this->ingredient_id) {
            $unitCost = (float) $data['total'] / (float) $data['quantity'];

            if ($unitCost < Ingredient::MIN_QUANTITY || $unitCost > Ingredient::MAX_QUANTITY) {
                $this->addError('quantity', 'Con esa cantidad y ese total, el precio por unidad sale fuera de lo normal. Revisa los dos.');

                return;
            }
        }

        DB::transaction(function () use ($data) {
            $expense = $this->editingId ? Expense::lockForUpdate()->find($this->editingId) : new Expense;

            if (! $expense) {
                throw ValidationException::withMessages(['total' => 'Este gasto se borró en otro equipo. Cierra el formulario y revisa la lista.']);
            }

            $previousIngredient = $expense->ingredient_id;
            $expense->fill($data + ['user_id' => auth()->id()])->save();

            // El costo del insumo sigue a su compra más reciente; si el gasto cambió de insumo, también se recalcula el anterior.
            Ingredient::whereIn('id', array_filter([$previousIngredient, $expense->ingredient_id]))->get()->each->syncCostFromPurchases();
        });

        $message = $this->editingId ? 'Gasto actualizado' : 'Gasto guardado';
        $this->resetForm();
        unset($this->expenses, $this->ingredients, $this->suppliers);
        $this->dispatch('toast', message: $message);
    }

    public function delete(int $id): void
    {
        $expense = Expense::findOrFail($id);
        $expense->delete();
        $expense->ingredient?->syncCostFromPurchases();
        unset($this->expenses);
        $this->dispatch('toast', message: 'Gasto borrado');
    }
};
?>


@php
    $total = (float) $this->expenses->sum('total');
    $byCategory = $this->expenses->groupBy(fn ($e) => $e->category->value)
        ->map(fn ($g, $key) => ['category' => \App\Enums\ExpenseCategory::from($key), 'total' => (float) $g->sum('total')])
        ->sortByDesc('total');
@endphp

<div x-data="{
        sheet: $wire.$entangle('showForm'),
        ings: @js($this->ingredients->map(fn ($i) => ['name' => $i->name, 'unit' => $i->unit, 'cost' => (float) $i->cost_per_unit])),
        get unit() { return this.ings[$wire.ingredient_id]?.unit ?? '' },
        get unitPrice() {
            const q = Number($wire.quantity), t = Number($wire.total);
            if (! $wire.ingredient_id || ! (q > 0) || ! (t > 0)) return null;
            const n = Math.round(t / q * 100) / 100;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: n % 1 ? 2 : 0 }).format(n) + ' por ' + this.unit;
        },
        // Un precio muy distinto al de la última compra casi siempre es una cantidad o un total mal escrito.
        get jump() {
            const q = Number($wire.quantity), t = Number($wire.total), before = this.ings[$wire.ingredient_id]?.cost ?? 0;
            if (! $wire.ingredient_id || ! (q > 0) || ! (t > 0) || ! (before > 0)) return '';
            const ratio = (t / q) / before;
            if (ratio < 3 && ratio > 1 / 3) return '';
            const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }).format(n);
            return 'Antes pagabas ' + money(before) + ' por ' + this.unit + ' y ahora sale a ' + money(Math.round(t / q * 100) / 100) + '. ¿La cantidad y el total están bien?';
        },
     }">
    <x-page-header title="Gastos" tour="gastos">
        <x-slot:actions>
            <button type="button" @click="sheet = true" class="btn-primary" data-tour="nuevo-gasto"><x-icon name="plus" class="size-4" /> Gasto</button>
        </x-slot:actions>
        <div class="flex items-center gap-2" data-tour="mes">
            <button type="button" wire:click="shiftMonth(-1)" class="btn-icon" aria-label="Mes anterior"><x-icon name="left" /></button>
            <p class="flex min-h-11 flex-1 items-center justify-center rounded-xl border border-line bg-surface font-semibold">{{ $this->monthLabel }}</p>
            <button type="button" wire:click="shiftMonth(1)" class="btn-icon" aria-label="Mes siguiente" @disabled($month >= today()->format('Y-m'))><x-icon name="right" /></button>
        </div>
    </x-page-header>

    <div wire:loading.delay.short wire:target="shiftMonth" class="w-full space-y-5">
        <div class="card h-40 animate-pulse !bg-sunken/60"></div>
        <x-skeleton-list :rows="4" />
    </div>

    <div wire:loading.remove.delay.short wire:target="shiftMonth" class="space-y-5">
        <section class="card !p-5" data-tour="gastos-mes">
            <p class="text-sm font-semibold text-muted">Gastado en el mes</p>
            <p class="num text-[3rem] leading-none font-bold">{{ money($total) }}</p>
            @if ($byCategory->isNotEmpty())
                <ul class="mt-4 space-y-2.5 border-t border-line pt-4">
                    @foreach ($byCategory as $row)
                        <li class="grid grid-cols-[1.75rem_6rem_1fr_auto] items-center gap-2.5 text-sm">
                            <span class="grid size-7 place-items-center rounded-lg {{ $row['category']->tone() }}"><x-icon :name="$row['category']->icon()" class="size-4" /></span>
                            <span class="truncate font-medium">{{ $row['category']->label() }}</span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-sunken"><span class="block h-full rounded-full bg-spend" style="width: {{ $total > 0 ? $row['total'] / $total * 100 : 0 }}%"></span></span>
                            <span class="num w-20 text-right text-base font-bold">{{ money($row['total']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($this->expenses->isEmpty())
            <x-empty icon="wallet" title="Sin gastos este mes">
                Registra lo que compras, como carne, tortilla o gas, para saber cuánto cuesta operar.
                <x-slot:action><button type="button" @click="sheet = true" class="btn-primary">Registrar un gasto</button></x-slot:action>
            </x-empty>
        @else
            @foreach ($this->expenses->groupBy(fn ($e) => $e->spent_on->toDateString()) as $day => $group)
                <section wire:key="day-{{ $day }}" class="space-y-2">
                    <div class="flex items-baseline justify-between px-1">
                        <h2 class="font-semibold">{{ ucfirst(\Carbon\CarbonImmutable::parse($day)->translatedFormat('l j')) }}</h2>
                        <span class="num text-base font-bold text-muted">{{ money($group->sum('total')) }}</span>
                    </div>
                    <ul class="list">
                        @foreach ($group as $e)
                            @php
                                $title = $e->ingredient?->name ?? $e->description ?? $e->category->label();
                                $meta = array_filter([
                                    $e->ingredient && $e->quantity ? rtrim(rtrim((string) $e->quantity, '0'), '.').' '.$e->ingredient->unit : null,
                                    $e->supplier,
                                    $e->ingredient && $e->description ? $e->description : null,
                                ]);
                            @endphp
                            <li wire:key="e-{{ $e->id }}" class="flex items-center gap-1 pr-2" @if ($loop->first) data-tour="gasto" @endif>
                                <button type="button" @click="sheet = true; $wire.edit({{ $e->id }})" class="flex min-w-0 flex-1 items-center gap-3 py-3 pl-4 text-left">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $e->category->tone() }}"><x-icon :name="$e->category->icon()" class="size-5" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold">{{ $title }}</span>
                                        <span class="flex items-center gap-2 truncate text-sm text-muted">
                                            @if ($meta) <span class="truncate">{{ implode(', ', $meta) }}</span> @elseif ($title !== $e->category->label()) <span>{{ $e->category->label() }}</span> @endif
                                            @unless ($e->from_cash) <span class="badge bg-sunken text-muted">Fuera de caja</span> @endunless
                                        </span>
                                    </span>
                                    <span class="num pl-2 text-[1.375rem] font-bold">{{ money($e->total) }}</span>
                                </button>
                                <button type="button" class="btn-quiet size-10 justify-center !px-0" aria-label="Borrar gasto"
                                        @click="if (await $store.confirm.ask({ title: '¿Borrar este gasto?', message: @js($title.' por '.money($e->total).'. No se puede deshacer.'), action: 'Borrar' })) $wire.delete({{ $e->id }})">
                                    <x-icon name="trash" class="size-4" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
    </div>

    <x-sheet :title="$editingId ? 'Editar gasto' : 'Nuevo gasto'" open="sheet" dismiss="sheet = false; $wire.cancel()">
        <div wire:loading.block wire:target="edit" class="space-y-4" aria-hidden="true">
            <div class="h-16 animate-pulse rounded-xl bg-sunken"></div>
            <div class="h-14 animate-pulse rounded-xl bg-sunken"></div>
            <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
            <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
        </div>

        <form wire:submit="save" id="expense-form" class="space-y-5" wire:loading.remove wire:target="edit">
            <div>
                <p class="label">Tipo</p>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                    @foreach (\App\Enums\ExpenseCategory::cases() as $c)
                        <button type="button" @click="$wire.category = '{{ $c->value }}'" :aria-pressed="$wire.category === '{{ $c->value }}'"
                                class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border text-sm font-semibold motion-safe:transition"
                                :class="$wire.category === '{{ $c->value }}' ? 'border-ink bg-ink text-bg' : 'border-line bg-surface text-ink hover:bg-sunken'">
                            <x-icon :name="$c->icon()" class="size-5" />
                            {{ $c->label() }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div x-show="$wire.category === 'ingredients'" class="space-y-2">
                <div class="grid grid-cols-[1fr_8rem] gap-3">
                    {{-- Al escoger el insumo, el cursor pasa a la cantidad. --}}
                    <div class="min-w-0" @pick="$nextTick(() => document.getElementById('quantity')?.focus())">
                        <label class="label" for="ingredient_id">Insumo</label>
                        <x-combobox id="ingredient_id" bind="$wire.ingredient_id" :options="$this->ingredients->map(fn ($i) => ['value' => $i->id, 'label' => $i->name, 'hint' => $i->unit])->values()->all()" none="Otro (sin insumo)" />
                    </div>
                    <div>
                        <label class="label" for="quantity">Cantidad</label>
                        <div class="relative">
                            <input id="quantity" type="number" inputmode="decimal" step="any" wire:model="quantity" :disabled="! $wire.ingredient_id" class="input pr-12 disabled:opacity-50">
                            <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted" x-text="unit"></span>
                        </div>
                    </div>
                </div>
                @error('ingredient_id') <p class="error">{{ $message }}</p> @enderror
                @error('quantity') <p class="error">{{ $message }}</p> @enderror
                @if ($this->ingredients->isEmpty())
                    <p class="hint">Aún no tienes insumos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold text-chile underline">Da de alta uno</a> para que el costo de tus recetas se actualice solo.</p>
                @endif
            </div>

            <div>
                <label class="label" for="total">Total pagado</label>
                <x-money-input id="total" wire:model="total" placeholder="0" class="!min-h-14 !text-2xl" />
                @error('total') <p class="error">{{ $message }}</p> @enderror
                <p class="error" x-show="jump" x-cloak x-text="jump"></p>
                <p class="hint" x-show="unitPrice" x-cloak>Sale a <span class="font-semibold text-ink" x-text="unitPrice"></span>. Ese será el nuevo costo del insumo.</p>
            </div>

            {{-- Junto al total para que se elija a conciencia: lo que sale de la caja se descuenta en el corte. --}}
            <div>
                <p class="label">¿Con qué dinero se pagó?</p>
                <div class="segmented" role="group" aria-label="Con qué dinero se pagó">
                    <button type="button" @click="$wire.from_cash = true" :aria-pressed="$wire.from_cash">De la caja</button>
                    <button type="button" @click="$wire.from_cash = false" :aria-pressed="! $wire.from_cash">De otro lado</button>
                </div>
                <p class="hint" x-text="$wire.from_cash ? 'Se descuenta del efectivo en el corte de ese día.' : 'Por ejemplo, de tu bolsillo o de la cuenta del negocio. No afecta el corte.'"></p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="supplier">Proveedor <span class="font-normal text-muted">(opcional)</span></label>
                    <input id="supplier" type="text" list="suppliers" wire:model="supplier" class="input" maxlength="255">
                    <datalist id="suppliers">
                        @foreach ($this->suppliers as $s) <option value="{{ $s }}"></option> @endforeach
                    </datalist>
                    @error('supplier') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="spent_on">Fecha</label>
                    <input id="spent_on" type="date" wire:model="spent_on" class="input" max="{{ today()->toDateString() }}">
                    @error('spent_on') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="label" for="description">Nota <span class="font-normal text-muted">(opcional)</span></label>
                <input id="description" type="text" wire:model="description" class="input" maxlength="255">
                @error('description') <p class="error">{{ $message }}</p> @enderror
            </div>
        </form>

        <x-slot:footer>
            <button type="submit" form="expense-form" wire:loading.attr="disabled" wire:target="save,edit" class="btn-primary btn-lg w-full">
                <span wire:loading.remove wire:target="save">{{ $editingId ? 'Guardar cambios' : 'Guardar gasto' }}</span>
                <span wire:loading wire:target="save">Guardando...</span>
            </button>
        </x-slot:footer>
    </x-sheet>
</div>
