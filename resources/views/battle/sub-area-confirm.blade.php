<x-layouts.facility title="記録済み入口" headerIconImage="images/icon/icon_003.webp" bgImage="images/bg-battle.webp">
    @php
        $powerRange = app(\App\Services\CharacterPowerService::class)->openingRecommendedRangeForLevels(
            (int) ($subArea->recommended_level_min ?? 1),
            (int) ($subArea->recommended_level_max ?? $subArea->recommended_level_min ?? 1)
        );
    @endphp
    <div class="mx-auto max-w-md px-4 py-5">
        @unless ($isResume)
            <a href="{{ route('home') }}" class="mb-4 inline-flex items-center text-sm font-bold text-slate-600 hover:text-slate-900">
                ← 探索画面へ戻る
            </a>
        @endunless

        <section class="overflow-hidden rounded-xl border border-indigo-200 bg-white shadow-sm">
            <div class="border-b border-indigo-100 bg-indigo-50 px-4 py-3">
                <div class="text-[11px] font-black uppercase tracking-[0.16em] text-indigo-600">Recorded Gate</div>
                <h1 class="mt-1 text-xl font-black text-slate-900">{{ $subArea->name }}</h1>
                <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">{{ $subArea->description }}</p>
            </div>

            <div class="space-y-3 px-4 py-4">
                @if ($isResume)
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-3 text-emerald-950">
                        <div class="text-xs font-black">中断していた亜域探索</div>
                        <p class="mt-1 text-xs font-bold leading-relaxed">
                            ログアウト前の進行状況と持ち込み品を引き継いでいます。
                        </p>
                        <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-md bg-white/80 px-2 py-1.5">
                                <div class="text-[10px] font-black text-emerald-700">探索度</div>
                                <div class="text-sm font-black">{{ number_format((int) ($explorationSummary['exploration_point'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-md bg-white/80 px-2 py-1.5">
                                <div class="text-[10px] font-black text-emerald-700">連戦</div>
                                <div class="text-sm font-black">{{ number_format((int) ($explorationSummary['chain_count'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-md bg-white/80 px-2 py-1.5">
                                <div class="text-[10px] font-black text-emerald-700">危険度</div>
                                <div class="text-sm font-black">{{ number_format((int) ($explorationSummary['danger_rate'] ?? 0)) }}%</div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">入口</div>
                    <div class="mt-0.5 text-sm font-black text-slate-800">{{ $route->route_name }}</div>
                    <div class="mt-1 text-xs font-bold leading-relaxed text-slate-500">{{ $route->entrance_description }}</div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                        <div class="text-[11px] font-black text-amber-700">開拓目安</div>
                        <div class="mt-0.5 text-lg font-black text-slate-900">
                            {{ app(\App\Services\CharacterPowerService::class)->formatRange($powerRange) }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <div class="text-[11px] font-black text-slate-400">発見元</div>
                        <div class="mt-0.5 truncate text-sm font-black text-slate-800">{{ $sourceArea?->name ?? '不明な入口' }}</div>
                    </div>
                </div>

                <p class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold leading-relaxed text-indigo-900">
                    通常ダンジョンとは別の探索として開始します。敵は目安戦力に合う強さまで強化され、経験値とドロップ率が少し高めになります。
                </p>

                <form action="{{ route('battle.sub_area.explore', ['discovery' => $discovery]) }}" method="POST">
                    @csrf
                    @if ($isResume)
                        <input type="hidden" name="continue_chain" value="1">
                    @endif
                    <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-md transition hover:bg-slate-800 active:scale-95">
                        {{ $isResume ? '探索を再開' : '探索開始' }}
                    </button>
                </form>

                @if ($isResume)
                    <form action="{{ route('battle.resume.return') }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-50 active:scale-95">
                            探索を切り上げて街へ帰還する
                        </button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</x-layouts.facility>
