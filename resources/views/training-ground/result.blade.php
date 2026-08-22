<x-layouts.facility
    title="訓練結果"
    subtitle="{{ $outcome['context_label'] ?? '模擬訓練' }}"
    headerIconImage="images/icon/icon_005.webp"
    bgImage="images/bg-battle.webp"
    :battleResultLayout="true"
    :showExit="false"
>
    @php
        $result = $outcome['result'];
        $turns = (int) data_get($result, 'turnCount', 0);
        $hpBefore = (int) data_get($result, 'playerHpBefore', 0);
        $hpAfter = (int) data_get($result, 'playerHpAfter', 0);
        $spBefore = (int) data_get($result, 'playerMpBefore', 0);
        $spAfter = (int) data_get($result, 'playerMpAfter', 0);
        $damageDealt = (int) data_get($result, 'damageDealt', 0);
        $damageTaken = (int) data_get($result, 'damageTaken', 0);
        $logs = (array) data_get($result, 'logs', []);
        $hud = data_get($result, 'jobArtV2Hud');
        $isPvp = ($outcome['context'] ?? '') === 'pvp';
        $attackerHp = (int) ($outcome['attacker_hp'] ?? 0);
        $attackerMaxHp = (int) ($outcome['attacker_max_hp'] ?? 0);
        $defenderHp = (int) ($outcome['defender_hp'] ?? 0);
        $defenderMaxHp = (int) ($outcome['defender_max_hp'] ?? 0);
    @endphp

    <div class="mx-auto max-w-4xl space-y-4 py-3">
        <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-xs font-black text-sky-700">{{ $outcome['context_label'] ?? '模擬訓練' }}</div>
                    <h2 class="mt-1 text-xl font-black text-sky-950">
                        @if($isPvp)
                            {{ ($outcome['attacker_won'] ?? false) ? '模擬戦に勝利した！' : '模擬戦で敗北した……' }}
                        @else
                            {{ $turns }}ターンの訓練を完了した！
                        @endif
                    </h2>
                </div>
                <div class="rounded-full border border-sky-300 bg-white px-3 py-1 text-xs font-black text-sky-800">戦果には反映されません</div>
            </div>

            @if($isPvp)
                <div class="mt-4 grid grid-cols-2 gap-2 text-xs font-bold sm:grid-cols-3">
                    <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">あなたの残りHP</span><div class="mt-1 font-black text-slate-950">{{ number_format($attackerHp) }} / {{ number_format($attackerMaxHp) }}</div></div>
                    <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">{{ $outcome['opponent_name'] ?? '相手' }}の残りHP</span><div class="mt-1 font-black text-slate-950">{{ number_format($defenderHp) }} / {{ number_format($defenderMaxHp) }}</div></div>
                    <div class="col-span-2 rounded-lg border border-sky-100 bg-white p-3 sm:col-span-1"><span class="text-slate-500">決着まで</span><div class="mt-1 text-lg font-black text-slate-950">{{ number_format($turns) }}ターン</div></div>
                </div>
            @else
                <div class="mt-4 grid grid-cols-2 gap-2 text-xs font-bold sm:grid-cols-4">
                    <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">与ダメージ</span><div class="mt-1 text-lg font-black text-slate-950">{{ number_format($damageDealt) }}</div></div>
                    <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">被ダメージ</span><div class="mt-1 text-lg font-black text-slate-950">{{ number_format($damageTaken) }}</div></div>
                    <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">訓練上のHP</span><div class="mt-1 font-black text-slate-950">{{ number_format($hpBefore) }} → {{ number_format($hpAfter) }}</div></div>
                    <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">訓練上のSP</span><div class="mt-1 font-black text-blue-800">{{ number_format($spBefore) }} → {{ number_format($spAfter) }}</div></div>
                </div>
            @endif
            <p class="mt-3 text-xs font-bold leading-relaxed text-slate-600">
                表示されているHP/SPは訓練内だけの値だ。実際のHP/SP、報酬、戦績、順位、対戦履歴、探索支援品、待機時間は変更されていない。
            </p>
        </section>

        @include('battle.partials.job-art-v2-hud', ['jobArtV2Hud' => $hud])

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 bg-slate-900 px-4 py-3 text-sm font-black text-white">
                {{ number_format($turns) }}ターンの戦闘ログ
                <span class="ml-2 text-[11px] text-slate-300">上から下へ発生順</span>
            </div>
            <div class="space-y-2 p-4 text-sm font-bold leading-relaxed text-slate-800">
                @foreach($logs as $line)
                    <div>{!! $line !!}</div>
                @endforeach
            </div>
        </section>

        <section class="grid gap-3 {{ $isPvp ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
            <form action="{{ route('training-ground.battle') }}" method="POST" data-submit-lock data-loading-text="{{ $isPvp ? '模擬戦中...' : '訓練中...' }}">
                @csrf
                <input type="hidden" name="context" value="{{ $outcome['context'] ?? 'pve' }}">
                @if($isPvp)
                    <input type="hidden" name="opponent_id" value="{{ $outcome['opponent_id'] ?? '' }}">
                @endif
                <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-sky-700 px-4 py-3 text-sm font-black text-white shadow active:scale-[0.99]">
                    {{ $isPvp ? '同じ相手ともう一度戦う' : '同じセットでもう一度訓練する' }}
                </button>
            </form>
            <a href="{{ route('training-ground.index') }}" data-navigation-lock data-loading-text="移動中..." class="inline-flex min-h-12 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-800 shadow-sm active:scale-[0.99]">{{ $isPvp ? '対戦相手を選び直す' : '訓練方法を選び直す' }}</a>
            @if($isPvp)
                <a href="{{ route('job-arts.index', ['context' => $pvpSetEnabled ? 'pvp' : 'boss']) }}" data-navigation-lock data-loading-text="移動中..." class="inline-flex min-h-12 items-center justify-center rounded-lg border border-violet-300 bg-violet-50 px-4 py-3 text-sm font-black text-violet-800 shadow-sm active:scale-[0.99]">{{ $pvpSetEnabled ? 'PvP戦技セットを見直す' : '対人用のボス戦技を見直す' }}</a>
            @endif
        </section>
    </div>
</x-layouts.facility>
