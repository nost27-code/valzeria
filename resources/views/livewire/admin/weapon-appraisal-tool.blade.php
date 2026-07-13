<div class="mx-auto max-w-5xl p-4 sm:p-6 lg:p-8">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">ADMIN TOOLS / APPRAISAL</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">銘・特攻武器 査定価格算出</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">良品・逸品を含む武器の市場査定額を、入力するだけで確認できます。</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-black text-slate-950">武器の条件</h2>
            <div class="mt-4 space-y-4">
                <label class="block text-sm font-black text-slate-700">ランク
                    <select wire:model.live="rank" class="mt-1 min-h-11 w-full rounded-md border-slate-300 font-bold">
                        @foreach($rankValues as $rankKey => $value)
                            <option value="{{ $rankKey }}">{{ $rankKey }}（本体 {{ number_format($value) }}G）</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-black text-slate-700">品質
                    <select wire:model.live="quality" class="mt-1 min-h-11 w-full rounded-md border-slate-300 font-bold">
                        @foreach($qualityLabels as $qualityKey => $label)
                            <option value="{{ $qualityKey }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-black text-slate-700">強化値
                    <select wire:model.live.number="enhanceLevel" class="mt-1 min-h-11 w-full rounded-md border-slate-300 font-bold">
                        @foreach(range(0, 3) as $level)
                            <option value="{{ $level }}">+{{ $level }}（本体補正 {{ [0 => '1.00', 1 => '1.03', 2 => '1.06', 3 => '1.10'][$level] }}倍）</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-black text-slate-700">銘
                    <select wire:model.live.number="engravingLevel" class="mt-1 min-h-11 w-full rounded-md border-slate-300 font-bold">
                        <option value="0">なし</option>
                        @foreach(range(1, $maxTraitLevel) as $level)
                            <option value="{{ $level }}">{{ ['I', 'II', 'III', 'IV', 'V'][$level - 1] }}（{{ number_format(config('equipment_market.trait_appraisal_values.' . $level)) }}G）</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-black text-slate-700">特攻
                    <select wire:model.live.number="slayerLevel" class="mt-1 min-h-11 w-full rounded-md border-slate-300 font-bold">
                        <option value="0">なし</option>
                        @foreach(range(1, $maxTraitLevel) as $level)
                            <option value="{{ $level }}">{{ ['I', 'II', 'III', 'IV', 'V'][$level - 1] }}（{{ number_format(config('equipment_market.trait_appraisal_values.' . $level)) }}G）</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <h2 class="text-lg font-black text-slate-950">査定結果</h2>
            @if($error)
                <p class="mt-4 rounded-md bg-red-100 p-3 text-sm font-bold text-red-700">{{ $error }}</p>
            @elseif($appraisal)
                <div class="mt-4 space-y-3 text-sm font-bold">
                    <div class="flex justify-between gap-4 border-b border-amber-200 pb-3"><span>装備本体査定</span><span>{{ number_format($appraisal['body_appraisal_price']) }}G</span></div>
                    <div class="flex justify-between gap-4 border-b border-amber-200 pb-3"><span>個体特性査定</span><span>{{ number_format($appraisal['trait_appraisal_price']) }}G</span></div>
                    @if($appraisal['trait_count'] === 2)
                        <p class="text-xs text-amber-800">銘・特攻の2特性は、高い方を100%、低い方を60%で加算しています。</p>
                    @endif
                    <div class="flex items-end justify-between gap-4 border-t-2 border-amber-300 pt-3"><span class="text-base">総査定額</span><span class="text-3xl font-black text-amber-900">{{ number_format($appraisal['appraisal_price']) }}G</span></div>
                    <div class="rounded-md bg-white/70 p-3 text-slate-700">市場で設定できる価格<br><span class="text-lg font-black">{{ number_format($appraisal['minimum_price']) }}〜{{ number_format($appraisal['maximum_price']) }}G</span></div>
                    <p class="text-xs text-amber-800">査定v{{ $appraisal['appraisal_version'] }}。既存の装備市場と同じ査定ルールです。</p>
                </div>
            @endif
        </section>
    </div>
</div>
