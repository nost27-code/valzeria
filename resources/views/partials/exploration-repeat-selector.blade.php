@once
<style>
    @container (max-width: 239px) {
        [data-exploration-repeat-grid] { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        [data-exploration-repeat-custom] { grid-column: 1 / -1; border-top: 1px solid #cbd5e1; }
    }
</style>
@endonce
<div style="container-type: inline-size; width: 100%;">
<div class="mb-1 flex items-center justify-between gap-2 text-xs font-black text-slate-700">
    <span>探索回数</span>
    <span class="text-[11px] text-slate-500">直接入力（2〜50回）</span>
</div>
<div data-exploration-repeat-grid class="grid overflow-hidden rounded-lg border border-slate-300 bg-white"
     style="grid-template-columns: 48px 48px minmax(144px, 1fr);"
     role="group"
     aria-label="探索回数">
    @foreach($quickRepeatCounts as $repeatCount)
        <button type="button"
                @click="selectFixedCount({{ $repeatCount }})"
                x-bind:aria-pressed="!customSelected && selectedCount === {{ $repeatCount }}"
                x-bind:class="!customSelected && selectedCount === {{ $repeatCount }} ? 'bg-sky-700 text-white shadow-inner' : 'bg-white text-slate-700 hover:bg-slate-50'"
                class="min-h-11 border-r border-slate-200 px-0.5 py-2 text-xs font-black transition last:border-r-0">
            {{ $repeatCount }}回
        </button>
    @endforeach
    <div data-exploration-repeat-custom class="flex h-11 min-w-0 items-stretch transition focus-within:ring-2 focus-within:ring-inset focus-within:ring-sky-300"
         x-bind:class="customSelected ? 'bg-sky-700 text-white shadow-inner' : 'bg-white text-slate-700'"
         role="group"
         aria-label="任意の探索回数を調整">
        <button type="button"
                @click="adjustCustomCount(-1)"
                x-bind:disabled="effectiveCount <= {{ $minCustomRepeatCount }}"
                aria-label="探索回数を1減らす"
                class="inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center border-r border-slate-300 text-xl font-black leading-none transition hover:bg-black/10 disabled:cursor-not-allowed disabled:opacity-40">
            <span aria-hidden="true">−</span>
        </button>
        <label class="flex h-11 min-w-0 flex-1 items-center justify-center">
            <span class="sr-only">探索回数</span>
            <input type="number"
                   min="{{ $minCustomRepeatCount }}"
                   max="{{ $maxRepeatCount }}"
                   step="1"
                   inputmode="numeric"
                   data-exploration-repeat-count
                   aria-label="任意の探索回数（2〜50回）"
                   x-model.number="customCount"
                   @focus="selectCustomCount(); $event.target.select()"
                   @input="customSelected = true; syncButtonState()"
                   @change="normalizeCustomCount()"
                   @blur="normalizeCustomCount()"
                   @keydown.enter.prevent="normalizeCustomCount()"
                   class="h-11 min-w-0 w-full border-0 bg-transparent px-1 text-right text-sm font-black tabular-nums text-current [appearance:textfield] focus:outline-none focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
            <span class="pr-2 text-[11px] font-black">回</span>
        </label>
        <button type="button"
                @click="adjustCustomCount(1)"
                x-bind:disabled="effectiveCount >= {{ $maxRepeatCount }}"
                aria-label="探索回数を1増やす"
                class="inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center border-l border-slate-300 text-xl font-black leading-none transition hover:bg-black/10 disabled:cursor-not-allowed disabled:opacity-40">
            <span aria-hidden="true">＋</span>
        </button>
    </div>
</div>
</div>
