<x-layouts.facility title="調達依頼" headerIconImage="images/facilities/facility_request_board.webp" bgImage="images/facilities/item.webp">
    <div class="w-full mx-auto pb-10">
        <div class="mb-3 flex items-center justify-between gap-3 px-1">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center text-sm font-black text-slate-500 transition hover:text-amber-700">
                ← 市場・依頼へ戻る
            </a>
            <div class="text-sm font-black text-slate-950">所持：{{ number_format((int) ($character->money ?? 0)) }}G</div>
        </div>

        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            <div class="mb-5 border-b border-slate-100 pb-4">
                <div class="text-xs font-black tracking-wide text-amber-700">NPC PROCUREMENT</div>
                <h2 class="mt-1 text-2xl font-black text-slate-950">調達依頼</h2>
                <p class="mt-1 text-sm font-bold leading-relaxed text-slate-500">
                    街や組織が必要としている素材を納品できます。納品した素材は消費され、即時にGold報酬を受け取れます。
                </p>
            </div>

            @if(session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    {{ session('error') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="space-y-3">
                @forelse($requests as $request)
                    @php
                        $requiredTotal = (int) $request->materials->sum('required_quantity');
                        $deliveredTotal = (int) $request->materials->sum('delivered_quantity');
                        $progress = $request->progressPercent();
                        $remainingSeconds = $request->remainingSeconds();
                        $remainingLabel = $remainingSeconds >= 3600
                            ? 'あと' . max(1, (int) ceil($remainingSeconds / 3600)) . '時間'
                            : 'あと' . max(1, (int) ceil($remainingSeconds / 60)) . '分';
                        $typeLabel = match((string) $request->requester_type) {
                            'blacksmith_guild' => '鍛冶',
                            'apothecary' => '薬品',
                            'city_guard' => '街道',
                            'guild' => '組合',
                            'association' => '協会',
                            default => '依頼',
                        };
                    @endphp
                    <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-black text-slate-950">{{ $request->title }}</h3>
                                <div class="mt-1 flex items-center gap-2 text-xs font-bold text-slate-500">
                                    @if($request->npc)
                                        <img src="{{ asset($request->npc->image_path) }}" alt="" class="h-8 w-8 shrink-0 object-contain">
                                    @endif
                                    <span class="min-w-0 truncate">依頼者：{{ $request->requester_name }}</span>
                                </div>
                                @if($request->purpose_label)
                                    <div class="mt-0.5 text-xs font-bold text-amber-700">用途：{{ $request->purpose_label }}</div>
                                @endif
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if($request->npc_procurement_request_template_id)
                                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-black text-sky-700">日替わり</span>
                                    @endif
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-600">{{ $typeLabel }}</span>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-black text-amber-700">{{ $remainingLabel }}</span>
                        </div>

                        <div class="mt-3">
                            <div class="mb-1 flex items-center justify-between text-[11px] font-black text-slate-500">
                                <span>納品進捗</span>
                                <span>{{ number_format($deliveredTotal) }} / {{ number_format($requiredTotal) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $progress }}%"></div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-2">
                            @foreach($request->materials as $requestMaterial)
                                @php
                                    $material = $requestMaterial->material;
                                    $remaining = $requestMaterial->remainingQuantity();
                                    $owned = (int) $requestMaterial->getAttribute('owned_quantity');
                                    $deliverable = (int) $requestMaterial->getAttribute('deliverable_quantity');
                                @endphp
                                <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-black text-slate-900">{{ $material?->displayName() ?? '不明な素材' }}</div>
                                            <div class="mt-0.5 text-xs font-bold text-slate-500">
                                                {{ number_format((int) $requestMaterial->delivered_quantity) }} / {{ number_format((int) $requestMaterial->required_quantity) }} 個
                                                ・報酬 {{ number_format((int) $requestMaterial->reward_gold_per_unit) }}G / 個
                                            </div>
                                            <div class="mt-0.5 text-[11px] font-bold text-slate-400">所持 {{ number_format($owned) }}個 / 納品可能 {{ number_format($deliverable) }}個</div>
                                        </div>
                                        <a href="{{ $material ? route('market.materials.show', $material) : '#' }}" wire:navigate class="shrink-0 text-[11px] font-black text-slate-500 underline decoration-slate-300 underline-offset-2">詳細</a>
                                    </div>

                                    @if($deliverable > 0)
                                        <form method="POST" action="{{ route('market.npc-requests.deliver', $requestMaterial) }}"
                                              class="mt-2 flex gap-1.5"
                                              x-data="{ qty: {{ $deliverable }}, submitting: false, confirmOpen: false, unitReward: {{ (int) $requestMaterial->reward_gold_per_unit }} }"
                                              @submit="submitting = true"
                                              @keydown.escape.window="confirmOpen = false">
                                            @csrf
                                            <input type="hidden" name="quantity" x-model="qty">
                                            <div class="flex h-8 shrink-0 overflow-hidden rounded border border-slate-300 bg-white">
                                                <button type="button" class="flex w-8 items-center justify-center text-sm font-black text-slate-600" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1">−</button>
                                                <span class="flex w-10 items-center justify-center border-x border-slate-300 text-sm font-bold text-slate-900" x-text="qty"></span>
                                                <button type="button" class="flex w-8 items-center justify-center text-sm font-black text-slate-600" @click="qty = Math.min({{ $deliverable }}, qty + 1)" :disabled="qty >= {{ $deliverable }}">＋</button>
                                            </div>
                                            <button type="button" @click="confirmOpen = true" :disabled="submitting" class="inline-flex h-8 flex-1 items-center justify-center rounded bg-emerald-600 px-3 text-xs font-black text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-60">
                                                <span x-text="submitting ? '納品中' : qty + '個納品する'"></span>
                                            </button>

                                            <div x-show="confirmOpen"
                                                 x-transition.opacity
                                                 class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-6"
                                                 style="display: none;">
                                                <div class="w-full max-w-sm rounded-2xl border border-amber-200 bg-white p-4 shadow-2xl"
                                                     @click.outside="confirmOpen = false">
                                                    <div class="text-xs font-black tracking-wide text-amber-700">DELIVERY CONFIRM</div>
                                                    <h4 class="mt-1 text-xl font-black text-slate-950">素材を納品しますか？</h4>
                                                    <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">
                                                        納品した素材は倉庫から消費されます。
                                                    </p>

                                                    <div class="mt-4 space-y-2 rounded-xl bg-slate-50 p-3 text-sm font-bold text-slate-600">
                                                        <div class="flex justify-between gap-3">
                                                            <span>依頼</span>
                                                            <span class="text-right text-slate-950">{{ $request->title }}</span>
                                                        </div>
                                                        <div class="flex justify-between gap-3">
                                                            <span>素材</span>
                                                            <span class="text-right text-slate-950">{{ $material?->displayName() ?? '不明な素材' }}</span>
                                                        </div>
                                                        <div class="flex justify-between gap-3">
                                                            <span>納品数</span>
                                                            <span class="text-right text-slate-950" x-text="qty + '個'"></span>
                                                        </div>
                                                        <div class="flex justify-between gap-3">
                                                            <span>獲得Gold</span>
                                                            <span class="text-right text-amber-700" x-text="(qty * unitReward).toLocaleString() + 'G'"></span>
                                                        </div>
                                                    </div>

                                                    <div class="mt-4 grid grid-cols-2 gap-2">
                                                        <button type="button" @click="confirmOpen = false" class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-black text-slate-600 shadow-sm">
                                                            キャンセル
                                                        </button>
                                                        <button type="submit" :disabled="submitting" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-3 py-2.5 text-sm font-black text-white shadow-sm disabled:opacity-60">
                                                            <span x-text="submitting ? '納品中' : '納品を確定'"></span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    @elseif($remaining <= 0)
                                        <div class="mt-2 rounded bg-emerald-50 px-2 py-1 text-xs font-black text-emerald-700">この素材は必要数に達しています。</div>
                                    @else
                                        <div class="mt-2 rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-500">この素材はまだ所持していません。</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3 text-right">
                            <a href="{{ route('market.npc-requests.show', $request) }}" wire:navigate class="text-xs font-black text-amber-700 underline decoration-amber-300 underline-offset-2">依頼詳細を見る</a>
                        </div>
                    </article>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">
                        現在、受け付け中の調達依頼はありません。
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.facility>
