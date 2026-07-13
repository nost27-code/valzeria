<x-layouts.admin>
    @php
        $tabLabels = ['listings' => '販売一覧', 'history' => '売買履歴'];
        $statusLabels = ['active' => '出品中', 'sold' => '売買成立', 'expired' => '期限切れ', 'cancelled' => '取消', 'admin_cancelled' => '運営取消'];
    @endphp

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-black tracking-[0.24em] text-amber-600">EQUIPMENT MARKET</p>
                <h1 class="mt-2 text-3xl font-black text-slate-950">装備市場管理</h1>
                <p class="mt-2 text-sm font-bold text-slate-500">銘・特攻付き武器の販売中の内容と、成立した売買を確認します。</p>
            </div>
            <div class="flex gap-2">
                @foreach($tabLabels as $value => $label)
                    <a href="{{ route('admin.equipment-market.index', ['tab' => $value]) }}"
                       class="rounded-md px-4 py-2 text-sm font-black shadow-sm ring-1 transition {{ $tab === $value ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                        {{ $label }}
                        <span class="ml-1 text-xs {{ $tab === $value ? 'text-amber-200' : 'text-slate-400' }}">{{ $value === 'listings' ? $summary['active'] : $summary['sold'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        @if(session('status'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800">{{ session('error') }}</div>
        @endif

        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach(['出品中' => $summary['active'], '成立件数' => $summary['sold'], '売買総額' => number_format($summary['gross']).'G', '手数料総額' => number_format($summary['fees']).'G', '平均販売額' => number_format($summary['average']).'G'] as $label => $value)
                <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="text-xs font-black text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-xl font-black text-slate-950">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @if($tab === 'listings')
            <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900">
                現在販売中の武器です。出品時点のスナップショットを表示しています。
            </div>
            <div class="space-y-3">
                @forelse($listings as $listing)
                    @php($snapshot = $listing->item_snapshot ?? [])
                    <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-black text-slate-950">{{ $listing->display_name_snapshot }}</h2>
                                    <span class="rounded bg-emerald-100 px-2 py-1 text-[11px] font-black text-emerald-800">{{ $statusLabels[$listing->status] ?? $listing->status }}</span>
                                </div>
                                <div class="mt-1 text-xs font-bold text-slate-500">
                                    {{ strtoupper((string) ($snapshot['weapon_rank'] ?? $listing->weapon_rank ?? '-')) }} ・ {{ $snapshot['weapon_category'] ?? $listing->weapon_category ?? '-' }} ・ 強化 +{{ $snapshot['enhance_level'] ?? $listing->enhance_level }} ・ 個体 #{{ $listing->character_item_id }}
                                </div>
                                @include('equipment-market.partials.effect-badges', ['base' => $snapshot['base_performance_lines'] ?? [], 'engraving' => $snapshot['engraving_effect_lines'] ?? [], 'slayer' => $snapshot['slayer_effect_lines'] ?? []])
                            </div>
                            <div class="shrink-0 text-left lg:text-right">
                                <div class="text-xs font-black text-slate-500">出品者</div>
                                <div class="text-sm font-black text-slate-950">{{ $listing->seller?->name ?? '-' }}</div>
                                <div class="mt-2 text-lg font-black text-violet-700">{{ number_format($listing->listing_price) }}G</div>
                                <div class="text-xs font-bold text-slate-500">期限 {{ $listing->expires_at?->format('Y/m/d H:i') ?? '-' }}</div>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs font-bold text-slate-500">
                            <span>査定 {{ number_format($listing->appraisal_price) }}G</span>
                            <span>価格範囲 {{ number_format($listing->minimum_price) }}〜{{ number_format($listing->maximum_price) }}G</span>
                            <span>査定v{{ $listing->appraisal_version }}</span>
                            <form method="POST" action="{{ route('admin.equipment-market.cancel', $listing) }}" onsubmit="return confirm('手数料なしで出品者へ返却します。')" class="ml-auto">
                                @csrf
                                <button class="rounded border border-red-200 px-3 py-1.5 font-black text-red-700 hover:bg-red-50">運営取消</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="rounded-md border border-dashed border-slate-300 bg-white px-4 py-12 text-center text-sm font-black text-slate-500">現在販売中の武器はありません。</div>
                @endforelse
            </div>
        @else
            <div class="mb-5 rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="font-black text-slate-950">ランク別販売件数</h2>
                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold">
                    @forelse($byRank as $row)
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $row->weapon_rank ?: '-' }}: {{ $row->count }}件</span>
                    @empty
                        <span class="text-slate-500">まだ成立取引はありません。</span>
                    @endforelse
                </div>
            </div>
            <div class="space-y-3">
                @forelse($transactions as $transaction)
                    @php($snapshot = $transaction->item_snapshot ?? [])
                    <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <h2 class="text-base font-black text-slate-950">{{ $snapshot['display_name'] ?? $transaction->listing?->display_name_snapshot ?? '不明な武器' }}</h2>
                                <div class="mt-1 text-xs font-bold text-slate-500">
                                    {{ strtoupper((string) ($snapshot['weapon_rank'] ?? '-')) }} ・ {{ $snapshot['weapon_category'] ?? '-' }} ・ 強化 +{{ $snapshot['enhance_level'] ?? 0 }} ・ 個体 #{{ $transaction->character_item_id }}
                                </div>
                                @include('equipment-market.partials.effect-badges', ['base' => $snapshot['base_performance_lines'] ?? [], 'engraving' => $snapshot['engraving_effect_lines'] ?? [], 'slayer' => $snapshot['slayer_effect_lines'] ?? []])
                            </div>
                            <div class="shrink-0 text-left lg:text-right">
                                <div class="text-xs font-black text-slate-500">成立日時</div>
                                <div class="text-sm font-black text-slate-950">{{ $transaction->sold_at?->format('Y/m/d H:i') ?? '-' }}</div>
                                <div class="mt-2 text-lg font-black text-violet-700">{{ number_format($transaction->sale_price) }}G</div>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-2 border-t border-slate-100 pt-3 text-xs font-bold text-slate-600 sm:grid-cols-3">
                            <div>出品者：<span class="font-black text-slate-950">{{ $transaction->seller?->name ?? '-' }}</span></div>
                            <div>購入者：<span class="font-black text-slate-950">{{ $transaction->buyer?->name ?? '-' }}</span></div>
                            <div>手数料：<span class="font-black text-rose-700">{{ number_format($transaction->fee_amount) }}G</span> ／ 売手受取：<span class="font-black text-emerald-700">{{ number_format($transaction->seller_proceeds) }}G</span></div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-md border border-dashed border-slate-300 bg-white px-4 py-12 text-center text-sm font-black text-slate-500">成立した売買履歴はありません。</div>
                @endforelse
            </div>
        @endif
    </div>
</x-layouts.admin>
