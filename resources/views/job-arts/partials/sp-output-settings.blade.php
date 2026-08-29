@php
    $strategy = $contextStrategies[$slotContext] ?? [];
    $selectedOutput = (string) ($strategy['sp_output'] ?? 'none');
    $previews = $spOutputPreviews ?? [];
    $spOutputUiEnabled = $spOutputUiEnabled ?? false;
    $formatBps = static function (int $bps): string {
        $value = number_format($bps / 100, 2, '.', '');

        return rtrim(rtrim($value, '0'), '.').'%';
    };
    $formatRange = static function (int $min, int $max): string {
        return $min === $max ? number_format($min) : number_format($min).'〜'.number_format($max);
    };
@endphp

<div data-job-art-sp-output-container="{{ $slotContext }}">
    @if($spOutputUiEnabled)
        <form
            method="POST"
            action="{{ route('job-arts.sp-output') }}"
            class="border-t border-slate-200 px-3 py-3"
            data-job-art-sp-output="{{ $slotContext }}"
            data-saved-output="{{ $selectedOutput }}"
        >
            @csrf
            <input type="hidden" name="slot_context" value="{{ $slotContext }}">

            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div class="text-xs font-black text-slate-900">SP出力</div>
                    <p class="mt-0.5 text-[10px] font-bold leading-relaxed text-slate-500">
                        セット内の対象戦技に一括で適用します。各戦技の合計消費SPは、下の戦技カードで確認できます。
                    </p>
                </div>
                <span class="hidden text-[10px] font-black" data-job-art-sp-output-status aria-live="polite"></span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                @foreach($spOutputLabels as $outputKey => $outputLabel)
                    @php($preview = $previews[$outputKey] ?? [])
                    <label class="last:col-span-2 sm:last:col-span-1">
                        <input
                            type="radio"
                            name="sp_output"
                            value="{{ $outputKey }}"
                            class="peer sr-only"
                            data-job-art-sp-output-radio
                            @checked($selectedOutput === $outputKey)
                        >
                        <span class="flex min-h-[5.25rem] cursor-pointer flex-col justify-center rounded-lg border border-slate-200 bg-white px-2 py-2 text-center transition-colors peer-checked:border-cyan-500 peer-checked:bg-cyan-50 peer-checked:ring-1 peer-checked:ring-cyan-400">
                            <span class="text-xs font-black text-slate-800">{{ $outputLabel }}</span>
                            @if(($preview['eligible_count'] ?? 0) > 0)
                                <span class="mt-1 text-[10px] font-black text-cyan-800">攻撃威力: +{{ $formatBps((int) ($preview['bonus_bps'] ?? 0)) }}</span>
                                <span class="mt-0.5 text-[9px] font-bold leading-tight text-slate-500">
                                    セット内の追加SP: {{ $formatRange((int) ($preview['variable_min'] ?? 0), (int) ($preview['variable_max'] ?? 0)) }}<br>
                                    セット内の合計SP: {{ $formatRange((int) ($preview['total_min'] ?? 0), (int) ($preview['total_max'] ?? 0)) }}
                                </span>
                            @else
                                <span class="mt-1 text-[9px] font-bold leading-tight text-slate-400">対象の攻撃戦技なし</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            <details class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-[10px] font-bold leading-relaxed text-slate-600">
                <summary class="min-h-6 cursor-pointer font-black text-indigo-700">消費と出力の仕組み</summary>
                <div class="mt-2 space-y-1.5">
                    <p>出力を選ぶと、各戦技カードの表示が「固定SP＋追加SP＝合計消費SP」に切り替わります。戦技ごとの必要SPはカード側で確認してください。</p>
                    <p>追加SPは、始動（Rank1）＜連携（Rank5）＜奥義（Rank9）の順に増えます。同じRankでも出力を高くするほど威力1%あたりの消費が増えます。威力上昇率は同じ出力なら共通で、最大SP10,000までは大きく、その先は緩やかに伸びます。</p>
                    <p>SP回復・HPからSPへの変換を持つ戦技と、直接ダメージを与えない戦技には追加消費も威力補正も付きません。</p>
                    @if($slotContext === 'pvp')
                        @php($budget = (int) data_get($previews, 'max.budget_initial', 0))
                        <p class="rounded-md bg-amber-50 px-2 py-1.5 text-amber-900">対人戦の出力予算は、このキャラクターでは1戦 {{ number_format($budget) }} SP分です。追加SPだけが予算を使い、戦闘中にSPを回復しても出力予算は戻りません。予算不足の戦技は候補から外れます。</p>
                    @endif
                    <p>カードの数値は戦闘開始前の基準値です。固定費の戦闘中割引により、実際の合計消費SPは表示より少なくなる場合があります。実際に使った固定費・追加SP{{ $slotContext === 'pvp' ? '・残り出力予算' : '' }}は戦闘ログに表示されます。</p>
                </div>
            </details>
        </form>
    @endif
</div>
