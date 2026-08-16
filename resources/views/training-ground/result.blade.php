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
    @endphp

    <div class="mx-auto max-w-4xl space-y-4 py-3">
        <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-xs font-black text-sky-700">{{ $outcome['context_label'] ?? '模擬訓練' }}</div>
                    <h2 class="mt-1 text-xl font-black text-sky-950">{{ $turns }}ターンの訓練を完了した！</h2>
                </div>
                <div class="rounded-full border border-sky-300 bg-white px-3 py-1 text-xs font-black text-sky-800">戦果には反映されません</div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2 text-xs font-bold sm:grid-cols-4">
                <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">与ダメージ</span><div class="mt-1 text-lg font-black text-slate-950">{{ number_format($damageDealt) }}</div></div>
                <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">被ダメージ</span><div class="mt-1 text-lg font-black text-slate-950">{{ number_format($damageTaken) }}</div></div>
                <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">訓練上のHP</span><div class="mt-1 font-black text-slate-950">{{ number_format($hpBefore) }} → {{ number_format($hpAfter) }}</div></div>
                <div class="rounded-lg border border-sky-100 bg-white p-3"><span class="text-slate-500">訓練上のSP</span><div class="mt-1 font-black text-blue-800">{{ number_format($spBefore) }} → {{ number_format($spAfter) }}</div></div>
            </div>
            <p class="mt-3 text-xs font-bold leading-relaxed text-slate-600">
                表示されているHP/SPは訓練内だけの値だ。実際のHP/SP、報酬、戦績、探索支援品、待機時間は変更されていない。
            </p>
        </section>

        @include('battle.partials.job-art-v2-hud', ['jobArtV2Hud' => $hud])

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 bg-slate-900 px-4 py-3 text-sm font-black text-white">{{ number_format((int) ($outcome['max_turns'] ?? $turns)) }}ターンの戦闘ログ</div>
            <div class="space-y-2 p-4 text-sm font-bold leading-relaxed text-slate-800">
                @foreach($logs as $line)
                    <div>{!! $line !!}</div>
                @endforeach
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2">
            <form action="{{ route('training-ground.battle') }}" method="POST" data-submit-lock>
                @csrf
                <input type="hidden" name="context" value="{{ $outcome['context'] ?? 'pve' }}">
                <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-sky-700 px-4 py-3 text-sm font-black text-white shadow active:scale-[0.99]">同じセットでもう一度訓練する</button>
            </form>
            <a href="{{ route('training-ground.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-800 shadow-sm active:scale-[0.99]">奥義セットを選び直す</a>
        </section>
    </div>
</x-layouts.facility>
