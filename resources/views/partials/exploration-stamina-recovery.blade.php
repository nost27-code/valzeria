@once
@php
    $supportItemCounts = $supportItemCounts ?? app(\App\Services\AdventureSupportService::class)->countsFor($character);
    $supportItemControlService = app(\App\Services\AdventureSupportItemControlService::class);
    $staminaRecoveryChoices = collect(['explore_stamina_small_bottle', 'explore_stamina_potion'])
        ->map(function (string $itemKey) use ($supportItemCounts, $supportItemControlService) {
            $item = config("adventure_support.items.{$itemKey}");
            if (!$item) {
                return null;
            }

            $item = $supportItemControlService->effectiveItem($itemKey, $item);

            return [
                'key' => $itemKey,
                'name' => (string) ($item['name'] ?? $itemKey),
                'icon_image' => $item['icon_image'] ?? null,
                'effect_value' => (int) ($item['effect_value'] ?? 0),
                'price' => (int) ($item['price'] ?? 0),
                'original_price' => isset($item['original_price']) ? (int) $item['original_price'] : null,
                'sale_ends_at' => $item['sale_ends_at'] ?? null,
                'quantity' => (int) ($supportItemCounts[$itemKey] ?? 0),
                'use_url' => route('inventory.support-items.use', ['itemKey' => $itemKey]),
                'purchase_url' => route('kiseki.support.purchase'),
            ];
        })
        ->filter()
        ->values();
    $currentKiseki = (int) ($character->free_kiseki ?? 0) + (int) ($character->paid_kiseki ?? 0);
@endphp
<div id="batch-stamina-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/45 px-4 py-6" style="z-index: 10000; overflow-y: auto; overscroll-behavior: contain; pointer-events: auto; -webkit-overflow-scrolling: touch;" role="dialog" aria-modal="true" aria-labelledby="batch-stamina-modal-title">
    <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-2xl" style="max-height: calc(100dvh - 3rem); overflow-y: auto; overscroll-behavior: contain; pointer-events: auto; -webkit-overflow-scrolling: touch;">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 id="batch-stamina-modal-title" class="text-base font-black text-slate-900">探索力を回復して探索を続けますか？</h3>
                <p class="mt-1 text-xs font-bold leading-5 text-slate-500">
                    使うアイテムを選んでください。
                </p>
            </div>
            <button type="button" data-batch-stamina-modal-close class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-rose-200 bg-rose-50 text-sm font-black leading-none text-rose-600 shadow-sm transition hover:bg-rose-100 hover:text-rose-800 active:scale-95" aria-label="閉じる" title="閉じる">
                <span aria-hidden="true">×</span>
            </button>
        </div>
        <div class="mt-3 rounded border border-sky-100 bg-sky-50 px-3 py-2 text-xs font-extrabold text-sky-800">
            探索力 <span data-batch-stamina-current>{{ number_format((int) ($stamina['current'] ?? 0)) }}</span> / 必要 <span data-batch-stamina-required>{{ number_format($requiredStamina ?? 1) }}</span>
        </div>
        <div class="mt-2 rounded border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-extrabold text-amber-800">
            所持輝石 <span data-batch-stamina-kiseki>{{ number_format($currentKiseki) }}</span>
        </div>
        <p data-stamina-recovery-message role="status" aria-live="polite" class="mt-2 text-xs font-bold text-sky-800"></p>
        <div class="mt-3 flex flex-col gap-2">
            @foreach($staminaRecoveryChoices as $choice)
                @php($hasOwnedStaminaItem = (int) $choice['quantity'] > 0)
                @php($isDiscountedStaminaItem = ($choice['original_price'] ?? null) !== null && (int) $choice['original_price'] > (int) $choice['price'])
                <div class="rounded-lg border {{ $hasOwnedStaminaItem ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-white' }} p-2">
                    <button type="button"
                            data-batch-stamina-item
                            data-item-key="{{ $choice['key'] }}"
                            data-use-url="{{ $choice['use_url'] }}"
                            data-quantity="{{ $choice['quantity'] }}"
                            @disabled($choice['quantity'] <= 0)
                            class="flex w-full items-center justify-between rounded-md px-2 py-2 text-left transition {{ $hasOwnedStaminaItem ? 'bg-white text-emerald-900 shadow-sm ring-1 ring-emerald-200 hover:bg-emerald-50' : 'bg-slate-50 text-slate-500 disabled:cursor-not-allowed disabled:opacity-75' }}">
                        <span class="flex min-w-0 items-center gap-2">
                            @if($choice['icon_image'])
                                <img src="{{ asset($choice['icon_image']) }}" alt="" class="h-5 w-5 object-contain">
                            @endif
                            <span class="min-w-0">
                                <span class="block text-sm font-black text-slate-800">{{ $choice['name'] }}</span>
                                <span class="block text-[11px] font-bold text-slate-500">探索力 +{{ number_format($choice['effect_value']) }}</span>
                            </span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <span class="rounded bg-white px-2 py-0.5 text-[11px] font-black {{ $hasOwnedStaminaItem ? 'text-emerald-700 ring-1 ring-emerald-100' : 'text-slate-500 ring-1 ring-slate-100' }}">
                                所持 <span data-batch-stamina-item-count>{{ number_format($choice['quantity']) }}</span>
                            </span>
                            <span class="rounded px-2 py-0.5 text-[11px] font-black {{ $hasOwnedStaminaItem ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500' }}">
                                {{ $hasOwnedStaminaItem ? '所持分を使う' : '所持なし' }}
                            </span>
                        </span>
                    </button>
                    @unless($hasOwnedStaminaItem)
                        <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-2">
                            <div class="text-[11px] font-bold text-amber-800">所持していないため、輝石を消費して購入後に使用します。</div>
                            <button type="button"
                                    data-batch-stamina-buy
                                    data-item-key="{{ $choice['key'] }}"
                                    data-purchase-url="{{ $choice['purchase_url'] }}"
                                    data-price="{{ $choice['price'] }}"
                                    class="mt-1.5 flex w-full items-center justify-center gap-1 rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-black text-amber-900 transition hover:bg-amber-100 active:scale-95">
                                <span>輝石で購入して使う</span>
                                @if($isDiscountedStaminaItem)
                                    <span class="rounded bg-red-50 px-1 py-px text-[10px] text-red-600">セール</span>
                                    <span class="text-[11px] text-slate-400 line-through decoration-red-500 decoration-2">{{ number_format($choice['original_price']) }}</span>
                                @endif
                                <img src="{{ asset('images/icon/kiseki.webp') }}" alt="" class="h-3.5 w-3.5 object-contain">
                                <span>{{ number_format($choice['price']) }}</span>
                            </button>
                        </div>
                    @endunless
                </div>
            @endforeach
            <button type="button" data-batch-stamina-modal-close class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-50">
                閉じる
            </button>
        </div>
    </div>
</div>

@endonce
