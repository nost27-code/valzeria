<x-layouts.facility title="国家対抗レイドのランキング" subtitle="各国の力をひとつの戦果に" :exit-url="route('home')" exitLabel="街へ戻る">
    <div class="mx-auto max-w-3xl space-y-5 pb-6" data-nation-raid-rankings>
        @include('nation-raid.partials.navigation', ['eventId' => $event->id, 'active' => 'rankings', 'finished' => $event->status === 'completed'])
        <header class="border-b border-slate-200 pb-4">
            <p class="text-xs font-bold text-sky-800">{{ $portal['status_label'] }}</p>
            <h1 class="mt-2 break-words text-xl font-black text-slate-900">{{ $event->name }}</h1>
        </header>
        <section aria-labelledby="raid-nation-ranking-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="raid-nation-ranking-heading" class="text-lg font-black text-slate-900">国家総ダメージランキング</h2>
                <span class="text-xs text-slate-500">{{ ($portal['standings']['is_final'] ?? false) ? '最終順位' : '途中順位' }}</span>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-slate-600">ボスへ与えた総ダメージで競います。国家連携分も含み、同じダメージは同順位です。</p>
            @if($portal['standings'] === null)
                <p role="status" class="mt-5 text-sm text-slate-600">戦績を確認中です。しばらく待ってから読み直してください。</p>
            @else
                @if($portal['own_nation'])
                    <p class="mt-4 border-l-2 border-sky-600 pl-3 text-sm font-bold text-slate-800" data-raid-own-nation>
                        自国 {{ $portal['own_nation']['rank'] }}位
                        @if($portal['own_nation']['damage_gap'] !== null)
                            <span class="mt-1 block text-xs font-normal text-slate-600">上の順位まであと{{ number_format($portal['own_nation']['damage_gap']) }}ダメージ</span>
                        @endif
                    </p>
                @elseif($portal['own_nation_name'])
                    <p class="mt-4 text-xs text-slate-600">{{ $portal['own_nation_name'] }}の出撃記録はまだありません。</p>
                @else
                    <p class="mt-4 text-xs text-slate-600">無所属・国家集計外の戦果は、個人ランキングへ記録されます。</p>
                @endif
                <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <table class="w-full table-fixed text-left text-sm" data-raid-nation-ranking-table>
                        <caption class="sr-only">国家別の順位・総ダメージ・現在の連携状況</caption>
                        <colgroup><col class="w-12 sm:w-16"><col></colgroup>
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs text-slate-600"><tr><th scope="col" class="px-2 py-3 text-center">順位</th><th scope="col" class="px-3 py-3">国家 / 総ダメージ</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($portal['nations'] as $nation)
                                <tr @class(['bg-sky-50/60' => $nation['is_own']])>
                                    <td class="px-2 py-4 text-center align-top font-black tabular-nums text-slate-700">{{ $nation['rank'] }}<span class="ml-0.5 text-[10px] font-normal">位</span></td>
                                    <th scope="row" class="px-3 py-4 font-normal">
                                        <div class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                            <span class="min-w-0 break-words font-bold text-slate-900">{{ $nation['name'] }} @if($nation['is_own'])<span class="ml-1 text-[10px] font-bold text-sky-800">自国</span>@endif</span>
                                            <span class="font-black tabular-nums text-slate-900">{{ number_format($nation['damage']) }}</span>
                                        </div>
                                        <p class="mt-1 text-[11px] text-slate-500">参加 {{ $nation['participant_count'] }}人 · 連携分 {{ number_format($nation['coordination_damage']) }}</p>
                                        @if($nation['coordination']['steps'][0]['active'] ?? false)
                                            <div class="mt-2">@include('nation-raid.partials.coordination-badge', ['coordination' => $nation['coordination']])</div>
                                        @endif
                                    </th>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-4 py-6 text-sm text-slate-500">まだ国家の出撃記録がありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">国家帰属は開始時の記録で集計します。連携バッジは直近3時間の共闘人数に応じて表示され、期限で更新・終了します。新しい出撃は再読み込みで反映されます。</p>
            @endif
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                <span>{{ $portal['as_of'] }} 時点の戦績</span>
                <a href="{{ route('nation-raid.rankings', $event) }}" class="inline-flex min-h-11 items-center font-bold text-sky-800 underline underline-offset-4">最新のランキングへ更新</a>
            </div>
        </section>
        @if($portal['standings'] !== null)
            @include('nation-raid.standings', ['hideNationTotal' => true])
        @endif
    </div>
</x-layouts.facility>
