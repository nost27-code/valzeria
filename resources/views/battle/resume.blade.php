<x-layouts.facility title="探索中断" headerIconImage="images/icon/icon_002.webp" bgImage="images/bg-battle.webp" :showExit="false">
    <div class="w-full py-6">
        <section class="overflow-hidden rounded-xl border border-amber-300 bg-white shadow-sm">
            <div class="border-b border-amber-100 bg-amber-50 px-4 py-4 text-center">
                <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-white shadow-sm overflow-hidden"><img src="{{ asset('images/icon/icon_002.webp') }}" alt="" class="w-8 h-8 object-contain"></div>
                <h1 class="text-xl font-black text-slate-900">探索が中断されました</h1>
                <p class="mt-1 text-sm font-bold text-slate-600">再開しますか？</p>
            </div>

            <div class="space-y-3 px-4 py-4">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">探索中のエリア</div>
                    <div class="mt-0.5 text-base font-black text-slate-900">{{ $area?->name ?? '探索中のエリア' }}</div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-center">
                        <div class="text-[10px] font-black text-slate-400">探索度</div>
                        <div class="mt-0.5 text-lg font-black text-slate-900">{{ number_format((int) ($state->exploration_point ?? 0)) }}</div>
                    </div>
                    <div class="rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-center">
                        <div class="text-[10px] font-black text-orange-600">危険度</div>
                        <div class="mt-0.5 text-lg font-black text-slate-900">{{ number_format((int) ($state->danger_rate ?? 0)) }}%</div>
                    </div>
                    <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-center">
                        <div class="text-[10px] font-black text-indigo-600">連戦</div>
                        <div class="mt-0.5 text-lg font-black text-slate-900">{{ number_format((int) ($state->chain_count ?? 0)) }}</div>
                    </div>
                </div>

                <div class="rounded-lg border border-amber-200 bg-white px-3 py-3">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-black text-slate-900">この探索で得た物</h2>
                        <span class="text-[11px] font-bold text-amber-700">
                            素材 {{ number_format($lootSummary['material_total'] ?? 0) }} / 装備 {{ number_format($lootSummary['item_total'] ?? 0) }}
                        </span>
                    </div>

                    @if(($lootSummary['risk_total'] ?? 0) > 0)
                        <div class="mb-2 rounded border border-red-100 bg-red-50 px-2 py-1 text-[11px] font-bold text-red-700">
                            敗北すると、素材 {{ number_format($lootSummary['risk_material_total'] ?? 0) }} 個 / 装備 {{ number_format($lootSummary['risk_item_total'] ?? 0) }} 個を失う可能性があります。
                        </div>
                    @endif

                    @if(!empty($lootSummary['materials']) || !empty($lootSummary['items']))
                        <div class="space-y-2">
                            @if(!empty($lootSummary['materials']))
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($lootSummary['materials'] as $material)
                                        <span class="inline-flex items-center rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-[11px] font-bold text-slate-700">
                                            {{ $material['name'] }}
                                            <span class="ml-1 text-emerald-700">x{{ number_format($material['quantity']) }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            @if(!empty($lootSummary['items']))
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($lootSummary['items'] as $item)
                                        <span class="inline-flex items-center rounded border border-amber-100 bg-amber-50 px-2 py-1 text-[11px] font-bold text-slate-700">
                                            {{ $item['name'] }}
                                            @if(!empty($item['rank']))
                                                <span class="ml-1 text-amber-700">{{ $item['rank'] }}</span>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-xs font-bold text-slate-400">まだ戦利品はありません。</p>
                    @endif
                </div>

                <form action="{{ route('battle.explore', ['area' => (int) $state->area_id]) }}" method="POST">
                    @csrf
                    <input type="hidden" name="continue_chain" value="1">
                    <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-md transition hover:bg-slate-800 active:scale-95">
                        探索を続ける
                    </button>
                </form>

                <form action="{{ route('battle.resume.return') }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 active:scale-95">
                        街に帰還する
                    </button>
                </form>
            </div>
        </section>
    </div>
</x-layouts.facility>
