<x-layouts.facility title="素材詳細" headerIconImage="images/icon/icon_011.webp" bgImage="images/facilities/item.webp">
    <div class="w-full mx-auto pb-10">
        <div class="mb-3 flex items-center justify-between gap-3 px-1">
            <a href="{{ route('market.index') }}" wire:navigate class="inline-flex items-center text-sm font-black text-slate-500 transition hover:text-amber-700">
                ← 市場へ戻る
            </a>
            <div class="text-sm font-black text-slate-950">所持：{{ number_format((int) ($character->money ?? 0)) }}G</div>
        </div>

        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="text-xs font-black tracking-wide text-amber-700">MATERIAL DETAIL</div>
                    <h2 class="mt-1 text-2xl font-black text-slate-950">{{ $material->displayName() }}</h2>
                    <p class="mt-1 text-sm font-bold leading-relaxed text-slate-500">
                        {{ $info['usage_summary'] }}
                    </p>
                </div>
                <div class="shrink-0 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-right">
                    <div class="text-[11px] font-black text-slate-400">所持数</div>
                    <div class="text-xl font-black text-slate-950">{{ number_format($info['owned_quantity']) }}個</div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-md bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">市場在庫</div>
                    <div class="mt-1 text-base font-black text-slate-900">{{ number_format($info['active_market_quantity']) }}個</div>
                </div>
                <div class="rounded-md bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">市場最安</div>
                    <div class="mt-1 text-base font-black text-amber-700">
                        {{ $info['lowest_price'] !== null ? number_format($info['lowest_price']).'G' : '出品なし' }}
                    </div>
                </div>
                <div class="rounded-md bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">NPC売却</div>
                    <div class="mt-1 text-base font-black text-slate-900">{{ number_format($info['npc_sell_price']) }}G</div>
                </div>
                <div class="rounded-md bg-slate-50 px-3 py-2">
                    <div class="text-[11px] font-black text-slate-400">出品範囲</div>
                    <div class="mt-1 text-base font-black text-slate-900">
                        {{ number_format($info['market_min_price']) }}〜{{ number_format($info['market_max_price']) }}G
                    </div>
                </div>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <section>
                    <h3 class="text-sm font-black text-slate-800">主な用途</h3>
                    <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">{{ $info['usage_summary'] }}</p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @forelse($info['usage_tags'] as $tag)
                            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-700">{{ $tag }}</span>
                        @empty
                            <span class="text-xs font-bold text-slate-400">用途タグなし</span>
                        @endforelse
                    </div>
                </section>

                <section>
                    <h3 class="text-sm font-black text-slate-800">主な入手先</h3>
                    <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">{{ $info['acquisition_summary'] }}</p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @forelse($info['acquisition_tags'] as $tag)
                            <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700">{{ $tag }}</span>
                        @empty
                            <span class="text-xs font-bold text-slate-400">入手先タグなし</span>
                        @endforelse
                    </div>
                </section>
            </div>

            @if($info['market_hint'])
                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-bold leading-relaxed text-amber-800">
                    {{ $info['market_hint'] }}
                </div>
            @endif

            @if(! $info['is_marketable'])
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-bold text-red-700">
                    {{ $info['unavailable_reason'] }}
                </div>
            @endif

            <section class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <h3 class="text-sm font-black text-slate-800">現在の調達依頼</h3>
                <div class="mt-3 space-y-2">
                    @forelse($npcRequests as $request)
                        @php
                            $requestMaterial = $request->materials->first();
                            if ($request->isPersistentUntilCompleted()) {
                                $remainingLabel = '完納まで';
                                $requestScheduleLabel = '長期募集';
                            } else {
                                $remainingSeconds = $request->remainingSeconds();
                                $remainingLabel = $remainingSeconds >= 3600
                                    ? 'あと' . max(1, (int) ceil($remainingSeconds / 3600)) . '時間'
                                    : 'あと' . max(1, (int) ceil($remainingSeconds / 60)) . '分';
                                $requestScheduleLabel = $request->npc_procurement_request_template_id ? '日替わり' : null;
                            }
                        @endphp
                        <div class="rounded-lg border border-white bg-white px-3 py-2 shadow-sm">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <img src="{{ asset($request->requesterIconPath()) }}" alt="" class="h-7 w-7 shrink-0 object-contain">
                                        <div class="truncate text-sm font-black text-slate-900">{{ $request->requester_name }}</div>
                                    </div>
                                    <div class="mt-0.5 text-xs font-bold text-slate-500">{{ $request->title }}</div>
                                    @if($requestScheduleLabel)
                                        <div class="mt-1">
                                            <span class="rounded {{ $request->isPersistentUntilCompleted() ? 'bg-amber-50 text-amber-700' : 'bg-sky-50 text-sky-700' }} px-1.5 py-0.5 text-[10px] font-black">{{ $requestScheduleLabel }}</span>
                                        </div>
                                    @endif
                                    @if($requestMaterial)
                                        <div class="mt-1 text-xs font-bold text-slate-500">
                                            必要数：{{ number_format((int) $requestMaterial->delivered_quantity) }} / {{ number_format((int) $requestMaterial->required_quantity) }}
                                            ・報酬：{{ number_format((int) $requestMaterial->reward_gold_per_unit) }}G / 個
                                        </div>
                                    @endif
                                </div>
                                <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-black text-amber-700">{{ $remainingLabel }}</span>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <a href="{{ route('market.npc-requests.show', $request) }}" wire:navigate class="inline-flex flex-1 items-center justify-center rounded border border-slate-200 bg-white px-3 py-1.5 text-xs font-black text-slate-600">
                                    納品画面へ
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="rounded bg-white px-3 py-2 text-sm font-bold text-slate-500">
                            現在、この素材を募集している依頼はありません。
                        </div>
                    @endforelse
                </div>
            </section>

            <div class="mt-5 grid grid-cols-2 gap-2">
                <a href="{{ route('market.index', ['tab' => 'buy']) }}" wire:navigate class="inline-flex items-center justify-center rounded-md bg-amber-600 px-3 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-amber-700">
                    市場で買う
                </a>
                <a href="{{ route('market.index', ['tab' => 'sell']) }}" wire:navigate class="inline-flex items-center justify-center rounded-md border border-emerald-200 bg-white px-3 py-2.5 text-sm font-black text-emerald-700 shadow-sm transition hover:bg-emerald-50">
                    出品する
                </a>
            </div>
        </div>
    </div>
</x-layouts.facility>
