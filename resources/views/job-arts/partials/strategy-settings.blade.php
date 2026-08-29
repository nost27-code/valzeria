@php
    $strategy = $contextStrategies[$slotContext] ?? [
        'mode' => 'auto',
        'sp_policy' => 'aggressive',
        'settings' => [],
    ];
    $strategyMode = (string) ($strategy['mode'] ?? 'auto');
    $strategySettings = (array) ($strategy['settings'] ?? []);
    $spPolicy = (string) ($strategy['sp_policy'] ?? 'aggressive');
@endphp

<form
    method="POST"
    action="{{ route('job-arts.strategy') }}"
    class="border-t border-slate-200 px-3 py-3"
    data-job-art-strategy="{{ $slotContext }}"
    x-data="{ mode: @js($strategyMode), detailsOpen: false }"
>
    @csrf
    <input type="hidden" name="slot_context" value="{{ $slotContext }}">

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-xs font-black text-slate-900">戦略</div>
            <p class="mt-0.5 text-[10px] font-bold leading-relaxed text-slate-500">
                このセットに装備した戦技のうち、条件を満たす戦技の優先順を決めます。
            </p>
        </div>
        <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-50 p-1">
            @foreach($strategyModeLabels as $modeKey => $modeLabel)
                <label>
                    <input
                        type="radio"
                        name="strategy_mode"
                        value="{{ $modeKey }}"
                        class="peer sr-only"
                        x-model="mode"
                        @checked($strategyMode === $modeKey)
                    >
                    <span class="flex min-h-9 cursor-pointer items-center justify-center rounded-md border border-transparent px-3 text-[11px] font-black text-slate-600 transition-colors peer-checked:border-indigo-500 peer-checked:bg-indigo-600 peer-checked:text-white">
                        {{ $modeLabel }}
                    </span>
                </label>
            @endforeach
        </div>
    </div>

    <div x-show="mode === 'auto'" class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5" data-job-art-strategy-auto-summary>
        <div class="text-[11px] font-black text-emerald-900">おまかせの判断</div>
        <ul class="mt-1.5 space-y-1 text-[10px] font-bold leading-relaxed text-emerald-800">
            <li>・相手の奥義・敵の大技へ対応できる戦技と、浄化が必要な時の浄化型戦技を優先します。</li>
            <li>・HP30%以下では回復型戦技、敵の大技予告中は防御型戦技を優先します。</li>
            <li>・上の対策が不要な時、資源満タン後の最初の奥義を優先し、発動判定は100%成功します（命中は別判定）。</li>
            <li>・それ以外は現在の巡回順で、始動と連携を均等に使います。</li>
        </ul>
    </div>

    <div x-show="mode === 'custom'" x-cloak class="mt-4 space-y-5" data-job-art-strategy-custom>
        <div>
            <div class="text-[11px] font-black text-slate-800">1. SPの使い方</div>
            <p class="mt-0.5 text-[10px] font-bold leading-relaxed text-slate-500">装備中の全戦技へ適用する、既存のSP使用条件です。</p>
            <div class="mt-2 grid grid-cols-3 gap-1 rounded-lg bg-white p-1 shadow-sm">
                @foreach($activationPolicyLabels as $policyKey => $policyLabel)
                    <label>
                        <input
                            type="radio"
                            name="sp_policy"
                            value="{{ $policyKey }}"
                            class="peer sr-only"
                            data-job-art-strategy-sp-policy-radio
                            x-bind:disabled="mode === 'auto'"
                            @checked($spPolicy === $policyKey)
                        >
                        <span class="flex min-h-8 cursor-pointer items-center justify-center rounded-md px-1 text-[10px] font-black text-slate-500 peer-checked:bg-indigo-600 peer-checked:text-white">
                            {{ $policyLabel }}
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="mt-1.5 text-[10px] font-bold text-indigo-700" data-job-art-strategy-sp-description>{{ $activationPolicyDescriptions[$spPolicy] ?? '' }}</p>
        </div>

        @foreach($strategySettingDefinitions as $settingKey => $definition)
            <label class="block">
                <span class="block text-[11px] font-black text-slate-800">{{ $loop->iteration + 1 }}. {{ $definition['label'] }}</span>
                <span class="mt-0.5 block text-[10px] font-bold leading-relaxed text-slate-500">{{ $definition['description'] }}</span>
                <select
                    name="strategy_settings[{{ $settingKey }}]"
                    class="mt-2 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800 focus:border-indigo-500 focus:ring-indigo-500"
                >
                    @foreach($definition['options'] as $optionKey => $optionLabel)
                        <option value="{{ $optionKey }}" @selected(($strategySettings[$settingKey] ?? null) === $optionKey)>{{ $optionLabel }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
    </div>

    {{-- おまかせでcustom側のSP radioをdisabledにしても、保存payloadの必須値を欠かさない。 --}}
    <input type="hidden" name="sp_policy" value="{{ $spPolicy }}" x-bind:disabled="mode !== 'auto'">

    <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[10px] font-bold leading-relaxed text-amber-900">
        戦略は、装備中かつ本来の発動条件を満たす戦技の優先順だけを変えます。防御・回復・浄化などの行動や、未装備の戦技を新しく追加するものではありません。
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <button type="button" @click="detailsOpen = !detailsOpen" class="min-h-10 rounded-lg px-3 text-[11px] font-black text-indigo-700 hover:bg-indigo-50" x-text="detailsOpen ? '説明を閉じる' : '選択の仕組みを見る'"></button>
        <div class="flex items-center gap-2">
            <span class="hidden text-[10px] font-black" data-job-art-strategy-status aria-live="polite"></span>
            <button type="submit" class="min-h-10 rounded-lg bg-indigo-600 px-4 text-[11px] font-black text-white shadow-sm hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60" data-job-art-strategy-submit>
                戦略を保存
            </button>
        </div>
    </div>
    <p x-show="detailsOpen" x-cloak class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-[10px] font-bold leading-relaxed text-slate-600">
        同じ優先度の戦技は画面の巡回順に従います。1手番で発動判定する戦技は1つだけで、失敗しても後ろの戦技を再抽選しません。奥義の「必ず発動」は戦技の発動判定だけを100%にし、命中・回避や相手の対策行動は従来どおりです。
    </p>
</form>
