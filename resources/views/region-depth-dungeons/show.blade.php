<x-layouts.facility :title="$definition['name']" :headerIconImage="'images/' . ($definition['symbol_image'] ?? 'icon/icon_002.webp')" bgImage="images/bg-battle.webp">
    <div class="mx-auto w-full max-w-2xl space-y-4 py-4">
        <section class="rounded-xl border border-slate-700 bg-slate-950 p-4 text-white shadow-lg">
            <h1 class="text-xl font-black">{{ $definition['name'] }}</h1>
            <p class="mt-2 text-sm font-bold leading-relaxed text-slate-200">{{ $definition['description'] ?: '奥へ進むほど、魔物の気配が濃くなる。引き返すまで、張り詰めた空気は消えない。' }}</p>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3"><div class="flex min-w-0 items-center gap-2"><img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="h-8 w-8 shrink-0 rounded-full border border-slate-200 bg-slate-50 object-contain"><h2 class="truncate font-black text-slate-900">{{ $definition['name'] }}・個人記録</h2></div>@if($payload['ranking']['personal_rank'])<span class="shrink-0 rounded bg-amber-100 px-2 py-1 text-xs font-black text-amber-800">総合 {{ $payload['ranking']['personal_rank'] }}位</span>@endif</div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs font-bold"><div class="rounded bg-slate-50 p-2">最高危険度<br><strong class="text-base">{{ number_format((int) ($payload['record']->best_danger_rate ?? 0)) }}%</strong></div><div class="rounded bg-slate-50 p-2">最高連戦数<br><strong class="text-base">{{ number_format((int) ($payload['record']->best_chain_count ?? 0)) }}</strong></div><div class="rounded bg-slate-50 p-2">最高獲得EXP<br><strong class="text-base">{{ number_format((int) ($payload['record']->best_total_exp ?? 0)) }}</strong></div></div>
            <div class="mt-4 border-t border-slate-100 pt-3"><h3 class="text-sm font-black text-slate-800">到達危険度ランキング</h3><div class="mt-2 space-y-1.5">@forelse($payload['ranking']['others'] as $entry)<div class="flex items-center gap-2 rounded bg-slate-50 px-3 py-2 text-xs font-bold"><span class="w-8 text-center text-amber-700">{{ $entry['rank'] }}位</span><img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($entry['record']->character?->icon_path) }}" alt="{{ $entry['record']->character?->name ?? '冒険者' }}" class="h-8 w-8 shrink-0 rounded-full border border-slate-200 bg-white object-contain"><span class="min-w-0 flex-1 truncate text-slate-800">{{ $entry['record']->character?->name ?? '冒険者' }}</span><span class="shrink-0 text-slate-600">危険度 {{ number_format((int) $entry['record']->best_danger_rate) }}%</span></div>@empty<div class="rounded bg-slate-50 px-3 py-2 text-xs font-bold text-slate-500">まだ記録を残した冒険者はいない。</div>@endforelse</div></div>
        </section>

        @if($payload['run'])
            @php($danger = (int) ($state->danger_rate ?? 0))
            @php($hasStartedExploring = (int) $payload['run']->max_chain_count > 0 || (int) $payload['run']->total_exp > 0 || (int) $payload['run']->total_job_exp > 0)
            <section class="rounded-xl border border-orange-300 bg-orange-50 p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3"><h2 class="font-black text-slate-900">{{ $hasStartedExploring ? $definition['name'] . 'を探索中' : $definition['name'] . 'へ入場済み' }}</h2><span class="rounded bg-orange-600 px-2 py-1 text-xs font-black text-white">{{ $hasStartedExploring ? number_format($danger) . '% ' . $payload['danger_label'] : '出発前' }}</span></div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <form method="POST" action="{{ route('battle.explore', ['area' => $area->id]) }}">@csrf<input type="hidden" name="continue_chain" value="1"><button class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-black text-white">探索する</button></form>
                    <form method="POST" action="{{ route('battle.explore', ['area' => $area->id]) }}">@csrf<input type="hidden" name="continue_chain" value="1"><input type="hidden" name="batch_count" value="10"><button class="w-full rounded-lg bg-indigo-700 px-4 py-3 text-sm font-black text-white">×10探索</button></form>
                </div>
            </section>
        @else
            <section class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm">
                <h2 class="font-black text-slate-900">入場料</h2>
                <div class="mt-3 space-y-1 text-sm font-bold text-slate-700">
                    @foreach($payload['entry']['materials'] as $material)<div class="flex justify-between"><span>{{ $material['name'] }} ×{{ $material['required'] }}</span><span class="{{ $material['shortage'] ? 'text-red-600' : 'text-emerald-700' }}">所持 {{ $material['owned'] }}</span></div>@endforeach
                    <div class="flex justify-between border-t pt-2"><span>{{ number_format($payload['entry']['gold']) }}G</span><span class="{{ $payload['entry']['gold_shortage'] ? 'text-red-600' : 'text-emerald-700' }}">所持 {{ number_format($payload['entry']['gold_owned']) }}G</span></div>
                </div>
                <form class="mt-4" method="POST" action="{{ route('region-depth-dungeons.enter', ['dungeonKey' => $dungeonKey]) }}">@csrf<button class="w-full rounded-lg bg-orange-600 px-4 py-3 text-sm font-black text-white">{{ $definition['name'] }}へ入場する</button></form>
            </section>
        @endif

    </div>
</x-layouts.facility>
