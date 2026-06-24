<x-layouts.facility title="記録済み入口" headerIconImage="images/icon/icon_003.webp" bgImage="images/bg-battle.webp">
    <div class="mx-auto max-w-md px-4 py-5">
        <a href="{{ route('home') }}" class="mb-4 inline-flex items-center text-sm font-bold text-slate-600 hover:text-slate-900">
            ← 探索画面へ戻る
        </a>

        <section class="overflow-hidden rounded-xl border border-indigo-200 bg-white shadow-sm">
            <div class="border-b border-indigo-100 bg-indigo-50 px-4 py-3">
                <div class="text-[11px] font-black uppercase tracking-[0.16em] text-indigo-600">Recorded Gate</div>
                <h1 class="mt-1 text-xl font-black text-slate-900">{{ $subArea->name }}</h1>
                <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">{{ $subArea->description }}</p>
            </div>

            <div class="space-y-3 px-4 py-4">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">入口</div>
                    <div class="mt-0.5 text-sm font-black text-slate-800">{{ $route->route_name }}</div>
                    <div class="mt-1 text-xs font-bold leading-relaxed text-slate-500">{{ $route->entrance_description }}</div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                        <div class="text-[11px] font-black text-amber-700">推奨Lv</div>
                        <div class="mt-0.5 text-lg font-black text-slate-900">
                            {{ number_format($subArea->recommended_level_min) }}〜{{ number_format($subArea->recommended_level_max) }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <div class="text-[11px] font-black text-slate-400">発見元</div>
                        <div class="mt-0.5 truncate text-sm font-black text-slate-800">{{ $sourceArea?->name ?? '不明な入口' }}</div>
                    </div>
                </div>

                <p class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold leading-relaxed text-indigo-900">
                    通常ダンジョンとは別の探索として開始します。敵は推奨Lv帯まで強化され、経験値とドロップ率が少し高めになります。
                </p>

                <form action="{{ route('battle.sub_area.explore', ['discovery' => $discovery]) }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-md transition hover:bg-slate-800 active:scale-95">
                        探索開始
                    </button>
                </form>
            </div>
        </section>
    </div>
</x-layouts.facility>
