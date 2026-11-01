@php($cols = \App\Modules\Orders\Models\DiningTable::COLS)
@php($rows = \App\Modules\Orders\Models\DiningTable::ROWS)
@php($plan = $this->getPlan())
@php($group = $this->getCurrentGroup())
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('orders::orders.floor_plan.hint') }}</p>

    @if ($plan->count() > 1)
        <div class="flex flex-wrap gap-2">
            @foreach ($plan as $g)
                @php($id = $g['section']?->id ?? '')
                <x-filament::button size="sm" :color="($group['section']?->id ?? '') === $id ? 'primary' : 'gray'" wire:click="selectSection('{{ $id }}')">
                    {{ $g['section']?->name ?? __('orders::orders.no_section') }}
                </x-filament::button>
            @endforeach
        </div>
    @endif

    @if (! $group || $group['tables']->isEmpty())
        <p class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-gray-700">{{ __('orders::orders.floor_plan.empty') }}</p>
    @else
        <div
            x-data="floorPlanEditor({{ $cols }}, {{ $rows }})"
            x-ref="canvas"
            wire:key="plan-{{ $group['section']?->id ?? 'none' }}"
            class="fp-canvas"
            style="aspect-ratio: {{ $cols }} / {{ $rows }}; background-size: {{ 100 / $cols }}% {{ 100 / $rows }}%;"
        >
            @foreach ($group['tables'] as $table)
                @php($status = $table->status())
                <div
                    wire:key="table-{{ $table->id }}"
                    data-id="{{ $table->id }}" data-x="{{ $table->pos_x }}" data-y="{{ $table->pos_y }}" data-w="{{ $table->width }}" data-h="{{ $table->height }}"
                    @pointerdown="start($event, $el)" @pointermove="move($event, $el)" @pointerup="end($event, $el)" @pointercancel="end($event, $el)"
                    class="fp-table fp-{{ $status->value }} {{ $table->shape->value === 'round' ? 'fp-round' : '' }}"
                    style="left: {{ $table->pos_x / $cols * 100 }}%; top: {{ $table->pos_y / $rows * 100 }}%; width: {{ $table->width / $cols * 100 }}%; height: {{ $table->height / $rows * 100 }}%;"
                >
                    <strong>{{ $table->name }}</strong>
                    <small>{{ __('orders::orders.fields.seats') }} {{ $table->seats }}</small>
                    <a href="{{ $this->editUrl($table) }}" @pointerdown.stop>{{ __('filament-actions::edit.single.label') }}</a>
                </div>
            @endforeach
        </div>
    @endif

    <style>
        .fp-canvas { position: relative; width: 100%; user-select: none; overflow: hidden; border-radius: .75rem; touch-action: none;
            background-color: #f3f4f6; box-shadow: inset 0 0 0 1px #e5e7eb;
            background-image: linear-gradient(to right, rgba(156,163,175,.25) 1px, transparent 1px), linear-gradient(to bottom, rgba(156,163,175,.25) 1px, transparent 1px); }
        .dark .fp-canvas { background-color: #111827; box-shadow: inset 0 0 0 1px #1f2937; }
        .fp-table { position: absolute; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
            font-size: .8rem; line-height: 1.2; border-radius: .75rem; cursor: grab; box-shadow: 0 1px 3px rgba(0,0,0,.15), 0 0 0 2px #d1d5db;
            background: #fff; color: #111827; transition: box-shadow .15s; }
        .fp-table:active { cursor: grabbing; }
        .fp-table strong { font-size: 1rem; }
        .fp-table small { color: #6b7280; }
        .fp-table a { margin-top: .15rem; font-size: .7rem; text-decoration: underline; color: #059669; }
        .fp-round { border-radius: 9999px; }
        .fp-seated { background: #d1fae5; box-shadow: 0 0 0 2px #10b981; }
        .fp-ordered { background: #fef3c7; box-shadow: 0 0 0 2px #f59e0b; }
        .fp-merged { background: #e0f2fe; box-shadow: 0 0 0 2px #0ea5e9; }
        .dark .fp-table { background: #1f2937; color: #f9fafb; box-shadow: 0 0 0 2px #4b5563; }
        .dark .fp-table small { color: #9ca3af; }
        .dark .fp-seated { background: rgba(16,185,129,.25); box-shadow: 0 0 0 2px #10b981; }
        .dark .fp-ordered { background: rgba(245,158,11,.25); box-shadow: 0 0 0 2px #f59e0b; }
        .dark .fp-merged { background: rgba(14,165,233,.25); box-shadow: 0 0 0 2px #0ea5e9; }
    </style>

    <script>
        // Drag a table on the grid; on drop, snap to whole cells and tell Livewire to save.
        function floorPlanEditor(cols, rows) {
            return {
                drag: null,
                start(e, el) {
                    if (e.button !== undefined && e.button !== 0) return;
                    el.setPointerCapture(e.pointerId);
                    const rect = this.$refs.canvas.getBoundingClientRect();
                    this.drag = { id: el.dataset.id, startX: e.clientX, startY: e.clientY, x: +el.dataset.x, y: +el.dataset.y,
                        cellW: rect.width / cols, cellH: rect.height / rows, w: +el.dataset.w, h: +el.dataset.h };
                    el.style.zIndex = 10;
                },
                move(e, el) {
                    if (!this.drag || this.drag.id !== el.dataset.id) return;
                    const { x, y } = this.target(e);
                    el.style.left = (x / cols * 100) + '%';
                    el.style.top = (y / rows * 100) + '%';
                },
                end(e, el) {
                    if (!this.drag || this.drag.id !== el.dataset.id) return;
                    const { x, y } = this.target(e);
                    el.style.zIndex = '';
                    if (x !== this.drag.x || y !== this.drag.y) {
                        el.dataset.x = x; el.dataset.y = y;
                        this.$wire.moveTable(this.drag.id, x, y);
                    }
                    this.drag = null;
                },
                target(e) {
                    const d = this.drag;
                    const x = Math.max(0, Math.min(cols - d.w, Math.round(d.x + (e.clientX - d.startX) / d.cellW)));
                    const y = Math.max(0, Math.min(rows - d.h, Math.round(d.y + (e.clientY - d.startY) / d.cellH)));
                    return { x, y };
                },
            };
        }
    </script>
</x-filament-panels::page>
