@php
    $headerIconImage = 'images/icon/icon_044.webp';
    $bgImage = 'images/bg-town.png';
    $canClaimAny = collect($items)->contains(fn ($entry) => $entry['can_claim']);
@endphp

<x-layouts.facility :title="$categoryName" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto">
        <div class="bg-white p-5 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5">
                <div>
                    <h2 class="text-xl font-bold text-slate-800">回復アイテムの無料配布</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        薬草・回復薬・魔力水を、毎日それぞれ{{ $targetCount }}個まで補給できます。
                    </p>
                    <p class="mt-1 text-xs font-bold text-slate-500">
                        所持数が{{ $targetCount }}個に届かない分だけ受け取り、残りは補給所にストックされます。
                    </p>
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                        <p class="font-extrabold">「持てる分だけ持っていきな。残った分はここで預かっとくよ」</p>
                        <p class="mt-1 text-xs font-bold text-amber-800">
                            翌日になればまた各{{ $targetCount }}個まで補給できるからね。今日の分は探索で使い切るくらいが一番お得だよ。
                        </p>
                    </div>
                </div>

                <form action="{{ route('shop.items.claim_all') }}" method="POST" class="shrink-0">
                    @csrf
                    <button type="submit"
                            @disabled(!$canClaimAny)
                            class="w-full sm:w-auto min-h-11 rounded-lg px-5 py-2.5 text-sm font-extrabold shadow transition active:scale-95 {{ $canClaimAny ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-slate-200 text-slate-400 cursor-not-allowed' }}">
                        受け取れる分をまとめて補充
                    </button>
                </form>
            </div>

            @if(session('status'))
                <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded mb-4 whitespace-pre-line">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
                    {{ session('error') }}
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach($items as $entry)
                    <div class="rounded-lg border border-[#d4af37]/40 bg-gradient-to-b from-white to-amber-50/40 p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-800">{{ $entry['name'] }}</h3>
                                <p class="mt-1 text-sm font-bold text-amber-700">{{ $entry['effect'] }}</p>
                            </div>
                            @if($entry['claimed_today'])
                                <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-500">本日受取済み</span>
                            @elseif($entry['can_claim'])
                                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-700">
                                    {{ $entry['claimable_count'] > 0 ? '受取可能' : 'ストック可能' }}
                                </span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-700">十分あります</span>
                            @endif
                        </div>

                        <div class="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                            <div class="flex items-center justify-between text-sm font-bold">
                                <span class="text-slate-500">現在の所持数</span>
                                <span class="text-slate-800">{{ $entry['owned_count'] }} / {{ $entry['target_count'] }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                @php
                                    $rate = min(100, (int) floor(($entry['owned_count'] / max(1, $entry['target_count'])) * 100));
                                @endphp
                                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $rate }}%"></div>
                            </div>
                            <p class="mt-2 text-xs font-bold text-slate-500">
                                @if($entry['claimable_count'] > 0)
                                    あと{{ $entry['claimable_count'] }}個受け取れます。
                                @elseif($entry['can_claim'] && $entry['daily_remaining'] > 0)
                                    所持上限のため、本日分{{ $entry['daily_remaining'] }}個をストックできます。
                                @elseif($entry['claimed_today'] && ($entry['supplied_count'] > 0 || $entry['stocked_today'] > 0))
                                    本日 受取{{ $entry['supplied_count'] }}個 / ストック{{ $entry['stocked_today'] }}個。
                                @elseif($entry['claimed_today'])
                                    本日の無料補充は確認済みです。
                                @else
                                    {{ $entry['target_count'] }}個以上あるため、所持数が減ってから受け取れます。
                                @endif
                            </p>
                            <div class="mt-3 flex items-center justify-between rounded-md bg-amber-50 px-3 py-2 text-xs font-bold">
                                <span class="text-amber-700">補給所に残っている分</span>
                                <span class="text-slate-800">{{ $entry['depot_count'] }}個</span>
                            </div>
                            @if($entry['stocked_count'] > 0 && $entry['daily_remaining'] > 0)
                                <p class="mt-1 text-[11px] font-bold text-slate-500">
                                    内訳: 持ち越し{{ $entry['stocked_count'] }}個 / 今日の未受取{{ $entry['daily_remaining'] }}個
                                </p>
                            @endif
                        </div>

                        <form action="{{ $entry['item'] ? route('shop.items.claim', $entry['item']) : '#' }}" method="POST" class="mt-4">
                            @csrf
                            <button type="submit"
                                    @disabled(!$entry['can_claim'])
                                    class="w-full min-h-11 rounded-lg px-4 py-2 text-sm font-extrabold shadow transition active:scale-95 {{ $entry['can_claim'] ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-slate-200 text-slate-400 cursor-not-allowed' }}">
                                {{ $entry['can_claim'] ? '補充を受け取る' : '受け取り不可' }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-900">
                <p class="font-bold">補給所ストックについて</p>
                <p class="mt-1">所持数が上限で受け取れなかった分や、今日まだ受け取っていない分は補給所に残ります。探索で消費したあと、また受け取りに来てください。</p>
            </div>
        </div>
    </div>
</x-layouts.facility>
