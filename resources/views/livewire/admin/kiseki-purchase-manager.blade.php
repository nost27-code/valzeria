<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-6">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">STRIPE KISEKI AUDIT</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">Stripe・輝石付与監査</h1>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
            <select wire:model.live="displayMode" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                <option value="all">全プレイヤー</option>
                <option value="purchased">購入者のみ</option>
            </select>
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="名前やメアドで検索..." class="w-full sm:w-80 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 mb-6">
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">購入者数</div>
            <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($totals['buyer_count']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">購入回数</div>
            <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($totals['purchase_count']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">購入輝石</div>
            <div class="mt-2 text-2xl font-black text-sky-700">{{ number_format($totals['purchased_kiseki']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">購入金額</div>
            <div class="mt-2 text-2xl font-black text-amber-700">{{ number_format($totals['purchased_jpy']) }}円</div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-8 mb-6">
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">Webhook受信中</div>
            <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($auditTotals['received']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">付与成功</div>
            <div class="mt-2 text-2xl font-black text-emerald-700">{{ number_format($auditTotals['fulfilled']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">付与失敗</div>
            <div class="mt-2 text-2xl font-black text-red-700">{{ number_format($auditTotals['failed']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">二重付与防止</div>
            <div class="mt-2 text-2xl font-black text-amber-700">{{ number_format($auditTotals['duplicate']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">返金</div>
            <div class="mt-2 text-2xl font-black text-orange-700">{{ number_format($auditTotals['refunded']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">キャンセル</div>
            <div class="mt-2 text-2xl font-black text-slate-700">{{ number_format($auditTotals['canceled']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">手動付与履歴</div>
            <div class="mt-2 text-2xl font-black text-sky-700">{{ number_format($auditTotals['manual_grants']) }}</div>
        </div>
        <div class="rounded-md bg-white/95 p-4 shadow-sm ring-1 ring-slate-200">
            <div class="text-xs font-black text-slate-500">付与履歴未接続</div>
            <div class="mt-2 text-2xl font-black {{ $auditTotals['unmatched_orders'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ number_format($auditTotals['unmatched_orders']) }}</div>
        </div>
    </div>

    <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden mb-6">
        <div class="border-b border-slate-200 px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-black text-slate-950">Stripe・輝石付与監査ログ</h2>
                <p class="text-xs font-bold text-slate-500">Webhook単位で直近50件を表示します。</p>
            </div>
            @if(!$hasAuditTable)
                <div class="rounded bg-amber-100 px-3 py-1 text-xs font-black text-amber-800">migration未実行</div>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">Webhook受信</th>
                        <th class="px-4 py-3 text-left font-bold">Stripe決済ID</th>
                        <th class="px-4 py-3 text-left font-bold">ユーザー</th>
                        <th class="px-4 py-3 text-left font-bold">購入商品</th>
                        <th class="px-4 py-3 text-right font-bold">金額</th>
                        <th class="px-4 py-3 text-right font-bold">付与輝石</th>
                        <th class="px-4 py-3 text-left font-bold">状態</th>
                        <th class="px-4 py-3 text-left font-bold">二重付与防止キー</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($latestAudits as $audit)
                        @php
                            $auditStatusClass = match($audit->status) {
                                'fulfilled' => 'bg-emerald-100 text-emerald-700',
                                'failed' => 'bg-red-100 text-red-700',
                                'duplicate' => 'bg-amber-100 text-amber-700',
                                'refunded' => 'bg-orange-100 text-orange-700',
                                'canceled' => 'bg-slate-200 text-slate-700',
                                default => 'bg-slate-100 text-slate-600',
                            };
                            $stripeId = $audit->stripe_session_id ?? $audit->stripe_payment_intent_id ?? $audit->stripe_charge_id ?? $audit->stripe_event_id;
                            $auditUser = $audit->character?->user ?? $audit->user;
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">
                                {{ $audit->webhook_received_at ? $audit->webhook_received_at->format('Y/m/d H:i:s') : '-' }}
                                <div class="text-[11px] text-slate-400">{{ $audit->event_type }}</div>
                            </td>
                            <td class="px-4 py-3 max-w-[280px] truncate text-xs font-bold text-slate-600">{{ $stripeId ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="font-black text-slate-900">User #{{ $audit->user_id ?? $auditUser?->id ?? '-' }}</div>
                                <div class="text-xs text-slate-500">{{ $audit->character?->name ?? 'キャラ不明' }} / {{ $auditUser?->email ?? 'N/A' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-slate-900">{{ $audit->product_name ?? ($packs[$audit->pack_key]['name'] ?? $audit->pack_key ?? '-') }}</div>
                                <div class="text-xs font-bold text-slate-500">{{ $audit->pack_key ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-black text-amber-700">{{ $audit->price_jpy !== null ? number_format($audit->price_jpy) . '円' : '-' }}</td>
                            <td class="px-4 py-3 text-right font-black text-sky-700">{{ $audit->kiseki_amount !== null ? number_format($audit->kiseki_amount) : '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex rounded px-2 py-1 text-[11px] font-black {{ $auditStatusClass }}">{{ $audit->status }}</span>
                                @if($audit->error_message)
                                    <div class="mt-1 max-w-[220px] truncate text-[11px] font-bold text-red-600">{{ $audit->error_message }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 max-w-[260px] truncate text-xs font-bold text-slate-500">{{ $audit->idempotency_key ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-8 text-center text-slate-500">監査ログはありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
        <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-lg font-black text-slate-950">手動付与履歴</h2>
                <p class="text-xs font-bold text-slate-500">manual/admin/adjustment系の輝石取引を表示します。</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">日時</th>
                            <th class="px-4 py-3 text-left font-bold">ユーザー</th>
                            <th class="px-4 py-3 text-right font-bold">輝石</th>
                            <th class="px-4 py-3 text-left font-bold">種別</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($manualGrantLogs as $log)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">{{ $log->created_at ? $log->created_at->format('Y/m/d H:i:s') : '-' }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-black text-slate-900">{{ $log->character?->name ?? '不明' }}</div>
                                    <div class="text-xs text-slate-500">{{ optional($log->character?->user)->email ?? 'N/A' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-black {{ $log->amount >= 0 ? 'text-sky-700' : 'text-red-700' }}">{{ number_format($log->amount) }}</td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-600">
                                    <div>{{ $log->transaction_type }} / {{ $log->source_type ?? '-' }}</div>
                                    <div class="mt-1 max-w-[280px] truncate text-slate-500">{{ $log->description ?? '-' }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-slate-500">手動付与履歴はありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-lg font-black text-slate-950">返金・キャンセル履歴</h2>
                <p class="text-xs font-bold text-slate-500">Stripeの返金/キャンセル系Webhookを表示します。</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">受信日時</th>
                            <th class="px-4 py-3 text-left font-bold">Stripe ID</th>
                            <th class="px-4 py-3 text-left font-bold">状態</th>
                            <th class="px-4 py-3 text-left font-bold">Event</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($refundCancelAudits as $audit)
                            @php
                                $terminalId = $audit->stripe_charge_id ?? $audit->stripe_payment_intent_id ?? $audit->stripe_session_id ?? $audit->stripe_event_id;
                                $terminalStatusClass = $audit->status === 'refunded' ? 'bg-orange-100 text-orange-700' : 'bg-slate-200 text-slate-700';
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">{{ $audit->webhook_received_at ? $audit->webhook_received_at->format('Y/m/d H:i:s') : '-' }}</td>
                                <td class="px-4 py-3 max-w-[300px] truncate text-xs font-bold text-slate-600">{{ $terminalId ?? '-' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap"><span class="inline-flex rounded px-2 py-1 text-[11px] font-black {{ $terminalStatusClass }}">{{ $audit->status }}</span></td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-500">{{ $audit->event_type }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-slate-500">返金・キャンセル履歴はありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden mb-6">
        <div class="border-b border-slate-200 px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-black text-slate-950">新着購入履歴</h2>
                <p class="text-xs font-bold text-slate-500">直近30件を新しい順に表示します。</p>
            </div>
            <div class="text-xs font-bold text-slate-500">
                {{ $displayMode === 'purchased' ? 'fulfilledのみ' : '全ステータス' }}
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">日時</th>
                        <th class="px-4 py-3 text-left font-bold">プレイヤー</th>
                        <th class="px-4 py-3 text-left font-bold">商品</th>
                        <th class="px-4 py-3 text-right font-bold">輝石</th>
                        <th class="px-4 py-3 text-right font-bold">金額</th>
                        <th class="px-4 py-3 text-left font-bold">状態</th>
                        <th class="px-4 py-3 text-left font-bold">Session</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($latestOrders as $order)
                        @php
                            $pack = $packs[$order->pack_key] ?? null;
                            $statusClass = match($order->status) {
                                'fulfilled' => 'bg-emerald-100 text-emerald-700',
                                'failed' => 'bg-red-100 text-red-700',
                                default => 'bg-slate-100 text-slate-600',
                            };
                            $orderedAt = $order->fulfilled_at ?? $order->created_at;
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">
                                {{ $orderedAt ? $orderedAt->format('Y/m/d H:i:s') : '-' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-black text-slate-900">{{ $order->character?->name ?? '不明' }}</div>
                                <div class="text-xs text-slate-500">{{ optional($order->character?->user)->email ?? 'N/A' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-slate-900">{{ $pack['name'] ?? $order->pack_key }}</div>
                                <div class="text-xs font-bold text-slate-500">注文ID {{ $order->id }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-black text-sky-700">
                                {{ number_format($order->kiseki_amount) }}
                            </td>
                            <td class="px-4 py-3 text-right font-black text-amber-700">
                                {{ number_format($order->price_jpy) }}円
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex rounded px-2 py-1 text-[11px] font-black {{ $statusClass }}">
                                    {{ $order->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 max-w-[260px] truncate text-xs font-bold text-slate-500">
                                {{ $order->session_id }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-slate-500">
                                購入履歴はありません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1.2fr)_minmax(420px,0.8fr)] gap-6">
        <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-lg font-black text-slate-950">プレイヤー別購入サマリー</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">プレイヤー</th>
                            <th class="px-4 py-3 text-right font-bold">購入回数</th>
                            <th class="px-4 py-3 text-right font-bold">購入輝石</th>
                            <th class="px-4 py-3 text-right font-bold">購入金額</th>
                            <th class="px-4 py-3 text-right font-bold">現在残高</th>
                            <th class="px-4 py-3 text-left font-bold">最終購入</th>
                            <th class="px-4 py-3 text-center font-bold">履歴</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($characters as $character)
                            @php
                                $isSelected = (int) $selectedCharacterId === (int) $character->id;
                                $hasPurchase = (int) $character->purchase_count > 0;
                            @endphp
                            <tr class="{{ $isSelected ? 'bg-amber-50' : 'hover:bg-slate-50' }}">
                                <td class="px-4 py-3">
                                    <div class="font-black text-slate-900">{{ $character->name }}</div>
                                    <div class="text-xs text-slate-500">{{ optional($character->user)->email ?? 'N/A' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold {{ $hasPurchase ? 'text-slate-900' : 'text-slate-400' }}">
                                    {{ number_format($character->purchase_count) }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-sky-700">
                                    {{ number_format($character->purchased_kiseki) }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-amber-700">
                                    {{ number_format($character->purchased_jpy) }}円
                                </td>
                                <td class="px-4 py-3 text-right text-xs font-bold text-slate-600">
                                    <div>有償 {{ number_format($character->paid_kiseki ?? 0) }}</div>
                                    <div>無償 {{ number_format($character->free_kiseki ?? 0) }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-500">
                                    {{ $character->last_purchase_at ? \Carbon\Carbon::parse($character->last_purchase_at)->format('Y/m/d H:i') : '-' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button type="button" wire:click="selectCharacter({{ $character->id }})" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-xs font-black transition {{ $isSelected ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                                        表示
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-slate-500">対象プレイヤーが見つかりません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-6 py-4">
                {{ $characters->links() }}
            </div>
        </div>

        <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-lg font-black text-slate-950">購入履歴</h2>
                @if($selectedCharacter)
                    <div class="mt-1 text-sm font-bold text-slate-600">{{ $selectedCharacter->name }} / {{ optional($selectedCharacter->user)->email ?? 'N/A' }}</div>
                @endif
            </div>

            @if(!$selectedCharacter)
                <div class="p-6 text-sm font-bold text-slate-500">プレイヤーを選択してください。</div>
            @else
                <div class="grid grid-cols-3 gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 text-xs font-black text-slate-600">
                    <div>
                        <div>現在有償</div>
                        <div class="mt-1 text-base text-amber-700">{{ number_format($selectedCharacter->paid_kiseki ?? 0) }}</div>
                    </div>
                    <div>
                        <div>現在無償</div>
                        <div class="mt-1 text-base text-sky-700">{{ number_format($selectedCharacter->free_kiseki ?? 0) }}</div>
                    </div>
                    <div>
                        <div>合計</div>
                        <div class="mt-1 text-base text-slate-950">{{ number_format($selectedCharacter->kiseki ?? 0) }}</div>
                    </div>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($selectedOrders as $order)
                        @php
                            $pack = $packs[$order->pack_key] ?? null;
                            $statusClass = $order->status === 'fulfilled' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600';
                        @endphp
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-black text-slate-900">{{ $pack['name'] ?? $order->pack_key }}</div>
                                    <div class="mt-1 text-xs font-bold text-slate-500">注文ID {{ $order->id }} / {{ $order->session_id }}</div>
                                </div>
                                <span class="shrink-0 rounded px-2 py-1 text-[11px] font-black {{ $statusClass }}">{{ $order->status }}</span>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-sm">
                                <div class="rounded bg-sky-50 px-3 py-2">
                                    <div class="text-[11px] font-black text-sky-700">輝石</div>
                                    <div class="font-black text-slate-950">{{ number_format($order->kiseki_amount) }}</div>
                                </div>
                                <div class="rounded bg-amber-50 px-3 py-2">
                                    <div class="text-[11px] font-black text-amber-700">金額</div>
                                    <div class="font-black text-slate-950">{{ number_format($order->price_jpy) }}円</div>
                                </div>
                                <div class="rounded bg-slate-50 px-3 py-2">
                                    <div class="text-[11px] font-black text-slate-500">完了日時</div>
                                    <div class="font-black text-slate-950">{{ $order->fulfilled_at ? $order->fulfilled_at->format('m/d H:i') : '-' }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-sm font-bold text-slate-500">購入履歴はありません。</div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>
