<main class="w-full px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
    <header class="mb-5 border-b border-slate-300 pb-5">
        <p class="text-xs font-black tracking-[0.18em] text-amber-700">ADMIN READ-ONLY LAB</p>
        <h1 class="mt-2 text-2xl font-black text-slate-950 sm:text-3xl">Valzeria Lab / 仮想冒険者</h1>
        <p class="mt-2 max-w-3xl text-sm font-bold leading-6 text-slate-600">メモリ上の合成冒険者で、街からボス挑戦までの判断と成長を観察します。</p>
    </header>

    @include('livewire.admin.valzeria-lab.tabs')

    <section class="border-b border-slate-300 pb-6">
        <h2 class="text-lg font-black text-slate-950">非永続プレイ</h2>
        <p class="mt-2 max-w-4xl text-sm font-bold leading-6 text-slate-600">既存の初期値とマスタから匿名状態を組み立て、戦闘だけは現行BattleServiceで実行します。行動選択と探索進行は比較用のLab簡略モデルで、実ゲームの自動攻略仕様ではありません。</p>

        <form wire:submit.prevent="runSimulation" class="mt-5">
            <fieldset>
                <legend class="text-sm font-black text-slate-900">行動方針</legend>
                <div class="mt-2 divide-y divide-slate-200 border-y border-slate-300 sm:grid sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    @foreach($profiles as $key => $definition)
                        <label class="flex cursor-pointer items-start gap-3 px-3 py-3 {{ $profile === $key ? 'bg-amber-50/70' : 'hover:bg-slate-50' }}">
                            <input type="radio" wire:model.live="profile" value="{{ $key }}" class="mt-0.5 border-slate-400 text-amber-600 focus:ring-amber-500">
                            <span>
                                <span class="block text-sm font-black text-slate-950">{{ $definition['label'] }}</span>
                                <span class="mt-0.5 block text-xs font-semibold leading-5 text-slate-500">{{ $definition['summary'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
                <label class="block">
                    <span class="text-xs font-black text-slate-700">行動上限（1〜100）</span>
                    <input type="number" wire:model="actionLimit" min="1" max="100" step="1" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-900 focus:border-amber-500 focus:ring-amber-200">
                    @error('actionLimit') <span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-700">乱数seed</span>
                    <input type="number" wire:model="seed" min="0" max="2147483647" step="1" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-900 focus:border-amber-500 focus:ring-amber-200">
                    @error('seed') <span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span> @enderror
                </label>
            </div>
            @error('profile') <p class="mt-2 text-xs font-bold text-rose-700">{{ $message }}</p> @enderror

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="runSimulation"
                        class="min-h-11 rounded-md bg-slate-950 px-5 py-2.5 text-sm font-black text-white hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                    <span wire:loading.remove wire:target="runSimulation">仮想試行を開始</span>
                    <span wire:loading wire:target="runSimulation">試行中...</span>
                </button>
                @if($result !== [])
                    <button type="button" wire:click="clearResult" class="inline-flex min-h-11 items-center px-1 text-xs font-black text-slate-500 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">結果を閉じる</button>
                @endif
                <span class="text-xs font-bold text-slate-500">Character・所持品・Gold・進行・戦績・ログは保存しません。</span>
            </div>
        </form>
    </section>

    @if($notice)
        <p class="border-b border-emerald-200 bg-emerald-50/60 px-1 py-3 text-sm font-black text-emerald-800">{{ $notice }}</p>
    @endif

    @if($result !== [])
        @php
            $initial = $result['initial'];
            $final = $result['final'];
        @endphp
        <section class="border-b border-slate-300 py-6" aria-labelledby="virtual-summary-heading">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-black text-amber-700">{{ $result['profile']['label'] }}方針 / seed {{ $result['seed'] }}</p>
                    <h2 id="virtual-summary-heading" class="mt-1 text-xl font-black text-slate-950">{{ $result['executed_actions'] }}行動で停止</h2>
                    <p class="mt-1 text-sm font-bold text-slate-600">停止理由: {{ $result['stop_reason_label'] }}</p>
                </div>
                <p class="text-xs font-black text-slate-500">永続化: {{ $result['persistence'] ? 'あり' : 'なし' }}</p>
            </div>

            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                @foreach(['開始' => $initial, '終了' => $final] as $heading => $state)
                    <div class="border-t border-slate-300 pt-3">
                        <h3 class="text-sm font-black text-slate-950">{{ $heading }}状態</h3>
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                            <div><dt class="text-xs font-bold text-slate-500">Lv / EXP</dt><dd class="font-black text-slate-900">{{ $state['level'] }} / {{ number_format($state['exp']) }}</dd></div>
                            <div><dt class="text-xs font-bold text-slate-500">HP</dt><dd class="font-black text-slate-900">{{ number_format($state['hp']) }} / {{ number_format($state['stats']['max_hp']) }}</dd></div>
                            <div><dt class="text-xs font-bold text-slate-500">SP</dt><dd class="font-black text-slate-900">{{ number_format($state['sp']) }} / {{ number_format($state['stats']['max_mp']) }}</dd></div>
                            <div><dt class="text-xs font-bold text-slate-500">Gold</dt><dd class="font-black text-slate-900">{{ number_format($state['gold']) }}G</dd></div>
                            <div><dt class="text-xs font-bold text-slate-500">職業</dt><dd class="font-black text-slate-900">{{ $state['job'] }} R{{ $state['job_rank'] }}</dd></div>
                            <div><dt class="text-xs font-bold text-slate-500">戦績</dt><dd class="font-black text-slate-900">{{ $state['wins'] }}勝 {{ $state['losses'] }}敗</dd></div>
                            <div class="col-span-2 sm:col-span-3"><dt class="text-xs font-bold text-slate-500">場所</dt><dd class="font-black text-slate-900">{{ $state['city'] }} / {{ $state['area'] }}</dd></div>
                            <div class="col-span-2 sm:col-span-3"><dt class="text-xs font-bold text-slate-500">装備</dt><dd class="font-black text-slate-900">{{ $state['equipment']['weapon'] ?? '武器なし' }} / {{ $state['equipment']['armor'] ?? '防具なし' }}</dd></div>
                        </dl>
                        <dl class="mt-3 grid grid-cols-4 gap-x-3 gap-y-2 border-t border-slate-200 pt-3 text-xs sm:grid-cols-8">
                            @foreach(['max_hp' => 'HP', 'max_mp' => 'SP', 'str' => '攻撃', 'def' => '防御', 'mag' => '魔力', 'spr' => '精神', 'agi' => '敏捷', 'luk' => '運'] as $key => $label)
                                <div><dt class="font-bold text-slate-500">{{ $label }}</dt><dd class="mt-0.5 font-black text-slate-900">{{ number_format($state['stats'][$key]) }}</dd></div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="border-b border-slate-300 py-6" aria-labelledby="virtual-boundaries-heading">
            <h2 id="virtual-boundaries-heading" class="text-lg font-black text-slate-950">計算境界</h2>
            <div class="mt-3 grid gap-5 lg:grid-cols-3">
                <div>
                    <h3 class="text-sm font-black text-emerald-800">現行実装を再利用</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs font-semibold leading-5 text-slate-600">
                        @foreach($result['boundaries']['exact'] as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-black text-amber-800">Lab簡略モデル</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs font-semibold leading-5 text-slate-600">
                        @foreach($result['boundaries']['simplified'] as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-700">今回モデル化しないもの</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs font-semibold leading-5 text-slate-600">
                        @foreach($result['boundaries']['not_modeled'] as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </div>
            </div>
        </section>

        <section class="py-6" aria-labelledby="virtual-timeline-heading">
            <h2 id="virtual-timeline-heading" class="text-lg font-black text-slate-950">行動タイムライン</h2>
            <p class="mt-1 text-xs font-semibold text-slate-500">上から下へ時系列です。各行は判断直後または戦闘結果反映後の状態を示します。</p>
            <ol class="mt-4 divide-y divide-slate-200 border-y border-slate-300">
                @foreach($result['timeline'] as $entry)
                    <li class="py-4" wire:key="virtual-step-{{ $entry['step'] }}">
                        <div class="flex items-start gap-3">
                            <span class="w-8 shrink-0 text-right text-xs font-black text-slate-400">{{ $entry['step'] }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <span class="text-xs font-black text-amber-800">{{ $entry['type_label'] }}</span>
                                    <h3 class="break-words text-sm font-black text-slate-950">{{ $entry['title'] }}</h3>
                                </div>
                                <p class="mt-1 break-words text-sm font-semibold leading-6 text-slate-700">{{ $entry['reason'] }}</p>
                                <p class="mt-1 break-all text-[11px] font-semibold leading-5 text-slate-500">根拠/処理: {{ $entry['engine'] }}</p>

                                @if($entry['battle'] && ($entry['battle']['result'] ?? null) !== 'encounter')
                                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-bold text-slate-600">
                                        <div><dt class="inline text-slate-400">結果 </dt><dd class="inline">{{ $entry['battle']['result_label'] }}</dd></div>
                                        <div><dt class="inline text-slate-400">与ダメージ </dt><dd class="inline">{{ number_format($entry['battle']['damage_dealt']) }}</dd></div>
                                        <div><dt class="inline text-slate-400">被ダメージ </dt><dd class="inline">{{ number_format($entry['battle']['damage_taken']) }}</dd></div>
                                        <div><dt class="inline text-slate-400">EXP </dt><dd class="inline">+{{ number_format($entry['battle']['exp']) }}</dd></div>
                                        <div><dt class="inline text-slate-400">Gold </dt><dd class="inline">+{{ number_format($entry['battle']['gold']) }}</dd></div>
                                        <div><dt class="inline text-slate-400">職業EXP </dt><dd class="inline">+{{ number_format($entry['battle']['job_exp']) }}</dd></div>
                                    </dl>
                                    @if($entry['battle']['log_excerpt'] !== [])
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-black text-slate-600">戦闘ログ抜粋</summary>
                                            <ul class="mt-2 space-y-1 border-l-2 border-slate-200 pl-3 text-xs font-semibold leading-5 text-slate-600">
                                                @foreach($entry['battle']['log_excerpt'] as $line)<li>{{ $line }}</li>@endforeach
                                            </ul>
                                        </details>
                                    @endif
                                @endif

                                @php
                                    $state = $entry['state'];
                                @endphp
                                <p class="mt-2 break-words text-xs font-bold leading-5 text-slate-500">
                                    Lv{{ $state['level'] }} / HP {{ number_format($state['hp']) }}・SP {{ number_format($state['sp']) }} / {{ number_format($state['gold']) }}G / {{ $state['job'] }} R{{ $state['job_rank'] }} / {{ $state['area'] }} / {{ $state['equipment']['weapon'] ?? '武器なし' }}・{{ $state['equipment']['armor'] ?? '防具なし' }}
                                </p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @else
        <section class="py-6">
            <h2 class="text-lg font-black text-slate-950">試行前</h2>
            <p class="mt-2 text-sm font-bold leading-6 text-slate-500">方針・行動上限・seedを選び、「仮想試行を開始」を押してください。既存Characterは選択せず、初期状態をその場で合成します。</p>
        </section>
    @endif
</main>
