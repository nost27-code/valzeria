<x-layouts.facility title="調達依頼詳細" headerIconImage="images/facilities/facility_request_board.webp" bgImage="images/facilities/item.webp">
    <div class="w-full mx-auto pb-10">
        <div class="mb-3 flex items-center justify-between gap-3 px-1">
            <a href="{{ route('market.npc-requests.index') }}" wire:navigate class="inline-flex items-center text-sm font-black text-slate-500 transition hover:text-amber-700">
                ← 調達依頼へ戻る
            </a>
            <div class="text-sm font-black text-slate-950">所持：{{ number_format((int) ($character->money ?? 0)) }}G</div>
        </div>

        @php
            $requiredTotal = (int) $request->materials->sum('required_quantity');
            $deliveredTotal = (int) $request->materials->sum('delivered_quantity');
            $progress = $request->progressPercent();
            if ($request->isPersistentUntilCompleted()) {
                $remainingLabel = '完納まで';
            } else {
                $remainingSeconds = $request->remainingSeconds();
                $remainingLabel = $remainingSeconds >= 3600
                    ? 'あと' . max(1, (int) ceil($remainingSeconds / 3600)) . '時間'
                    : 'あと' . max(1, (int) ceil($remainingSeconds / 60)) . '分';
            }
            $typeLabel = match((string) $request->requester_type) {
                'blacksmith_guild' => '鍛冶',
                'apothecary' => '薬品',
                'city_guard' => '街道',
                'guild' => '組合',
                'association' => '協会',
                default => '依頼',
            };
        @endphp

        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            @if(session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="border-b border-slate-100 pb-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-black tracking-wide text-amber-700">PROCUREMENT REQUEST</div>
                        <h2 class="mt-1 text-2xl font-black text-slate-950">{{ $request->title }}</h2>
                        <div class="mt-2 flex items-center gap-2 text-sm font-bold text-slate-500">
                            <img src="{{ asset($request->requesterIconPath()) }}" alt="" class="h-10 w-10 shrink-0 object-contain">
                            <span class="min-w-0 truncate">依頼者：{{ $request->requester_name }}</span>
                        </div>
                        @if($request->purpose_label)
                            <div class="mt-1 text-sm font-black text-amber-700">用途：{{ $request->purpose_label }}</div>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-1">
                            @if($request->isPersistentUntilCompleted())
                                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-black text-amber-700">長期募集</span>
                            @elseif($request->npc_procurement_request_template_id)
                                <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-black text-sky-700">日替わり</span>
                            @endif
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-600">{{ $typeLabel }}</span>
                        </div>
                    </div>
                    <span class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-700">{{ $remainingLabel }}</span>
                </div>
                @if($request->description)
                    <p class="mt-3 text-sm font-bold leading-relaxed text-slate-500">{{ $request->description }}</p>
                @endif
            </div>

            <div class="mt-4">
                <div class="mb-1 flex items-center justify-between text-xs font-black text-slate-500">
                    <span>納品進捗</span>
                    <span>{{ number_format($deliveredTotal) }} / {{ number_format($requiredTotal) }}個</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $progress }}%"></div>
                </div>
            </div>

            <div class="mt-5 space-y-3">
                @foreach($request->materials as $requestMaterial)
                    @php
                        $material = $requestMaterial->material;
                        $remaining = $requestMaterial->remainingQuantity();
                        $owned = (int) $requestMaterial->getAttribute('owned_quantity');
                        $deliverable = (int) $requestMaterial->getAttribute('deliverable_quantity');
                    @endphp
                    <section class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-base font-black text-slate-950">{{ $material?->displayName() ?? '不明な素材' }}</h3>
                                <p class="mt-1 text-xs font-bold text-slate-500">
                                    必要数 {{ number_format((int) $requestMaterial->delivered_quantity) }} / {{ number_format((int) $requestMaterial->required_quantity) }}個
                                    ・残り {{ number_format($remaining) }}個
                                </p>
                            </div>
                            <div class="shrink-0 rounded-lg bg-white px-3 py-2 text-right shadow-sm">
                                <div class="text-[10px] font-black text-slate-400">報酬単価</div>
                                <div class="text-sm font-black text-amber-700">{{ number_format((int) $requestMaterial->reward_gold_per_unit) }}G</div>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-bold">
                            <div class="rounded bg-white px-2 py-1.5">
                                <div class="text-[10px] text-slate-400">あなたの所持数</div>
                                <div class="text-sm font-black text-slate-900">{{ number_format($owned) }}個</div>
                            </div>
                            <div class="rounded bg-white px-2 py-1.5">
                                <div class="text-[10px] text-slate-400">納品可能</div>
                                <div class="text-sm font-black text-emerald-700">{{ number_format($deliverable) }}個</div>
                            </div>
                        </div>

                        @if($deliverable > 0)
                            <form method="POST" action="{{ route('market.npc-requests.deliver', $requestMaterial) }}"
                                  class="mt-3 flex gap-1.5"
                                  x-data="{ qty: {{ $deliverable }}, submitting: false, confirmOpen: false, unitReward: {{ (int) $requestMaterial->reward_gold_per_unit }} }"
                                  @submit="submitting = true"
                                  @keydown.escape.window="confirmOpen = false">
                                @csrf
                                <input type="hidden" name="quantity" x-model="qty">
                                <div class="flex h-9 shrink-0 overflow-hidden rounded border border-slate-300 bg-white">
                                    <button type="button" class="flex w-9 items-center justify-center text-base font-black text-slate-600" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1">−</button>
                                    <span class="flex w-12 items-center justify-center border-x border-slate-300 text-sm font-bold text-slate-900" x-text="qty"></span>
                                    <button type="button" class="flex w-9 items-center justify-center text-base font-black text-slate-600" @click="qty = Math.min({{ $deliverable }}, qty + 1)" :disabled="qty >= {{ $deliverable }}">＋</button>
                                </div>
                                <button type="button" @click="confirmOpen = true" :disabled="submitting" class="inline-flex h-9 flex-1 items-center justify-center rounded bg-emerald-600 px-3 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-60">
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
                            <div class="mt-3 rounded bg-emerald-50 px-3 py-2 text-sm font-black text-emerald-700">この素材は必要数に達しています。</div>
                        @else
                            <div class="mt-3 flex gap-2">
                                <div class="flex-1 rounded bg-slate-100 px-3 py-2 text-sm font-bold text-slate-500">この素材はまだ所持していません。</div>
                                @if($material)
                                    <a href="{{ route('market.materials.show', $material) }}" wire:navigate class="inline-flex items-center justify-center rounded border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-600">素材詳細</a>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.facility>
