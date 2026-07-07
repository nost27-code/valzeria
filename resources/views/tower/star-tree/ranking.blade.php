@php
    $towerUi = $towerUi ?? [];
    $towerName = (string) ($towerUi['name'] ?? '星樹の塔');
    $towerRankingTitle = (string) ($towerUi['ranking_title'] ?? ($towerName.'ランキング'));
    $rankClass = fn (int $rank): string => match ($rank) {
        1 => 'text-amber-600',
        2 => 'text-slate-500',
        3 => 'text-orange-700',
        default => 'text-slate-600',
    };
@endphp

<x-layouts.facility
    :title="$towerRankingTitle"
    headerIconImage="images/icon/icon_223.webp"
    bgImage="images/bg-castle.webp"
    headerOverlayClass="bg-slate-950/55"
    headerTitleClass="text-white"
    headerBorderClass="border-emerald-700"
>
    <div class="mx-auto w-full max-w-5xl space-y-5 pb-8">
        <div class="flex flex-col gap-3 rounded-lg border border-emerald-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="text-xs font-black text-emerald-700">{{ $towerUi['public_log_label'] ?? $towerName }}</div>
                <h2 class="mt-1 text-xl font-black text-slate-950">最高踏破階ランキング</h2>
                <p class="mt-1 text-sm font-bold text-slate-500">今週: {{ $seasonKey }}</p>
            </div>
            <a href="{{ route('tower.star-tree.index') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-slate-700">
                {{ $towerName }}へ戻る
            </a>
        </div>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-emerald-50 px-4 py-3">
                <h3 class="text-lg font-black text-slate-950">今週の最高踏破階</h3>
            </div>
            @include('tower.star-tree.ranking-table', ['records' => $weeklyRankings, 'character' => $character, 'rankClass' => $rankClass])
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                <h3 class="text-lg font-black text-slate-950">全期間最高踏破階</h3>
            </div>
            @include('tower.star-tree.ranking-table', ['records' => $allTimeRankings, 'character' => $character, 'rankClass' => $rankClass])
        </section>
    </div>
</x-layouts.facility>
