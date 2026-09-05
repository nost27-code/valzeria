<x-layouts.facility title="国家対抗レイドの戦果" subtitle="黒天竜との戦いの記録" :exit-url="route('home')" exitLabel="街へ戻る">
    <div class="mx-auto max-w-3xl space-y-6 pb-6" data-nation-raid-rewards>
        @include('nation-raid.partials.navigation', ['eventId' => $event->id, 'active' => 'rewards', 'finished' => $event->status === 'completed'])
        <header class="border-b border-slate-200 pb-5">
            <a href="{{ route('nation-raid.history') }}" class="mb-3 inline-flex min-h-11 items-center gap-2 text-sm font-bold text-sky-800 underline underline-offset-4">
                <img src="{{ asset('images/icon/icon_012.webp') }}" alt="" width="24" height="24" class="h-6 w-6 object-contain">過去の戦果・未受取報酬
            </a>
            <h1 class="text-xl font-black leading-relaxed text-slate-900">{{ $event->name }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ $event->starts_at->format('Y/n/j') }} 〜 {{ $event->ends_at->format('Y/n/j') }}</p>
        </header>
        @if(session('success'))<p role="status" class="text-sm text-emerald-700">{{ session('success') }}</p>@endif
        @if(session('error'))<p role="alert" class="text-sm text-red-700">{{ session('error') }}</p>@endif
        @if($errors->any())<p role="alert" class="text-sm text-red-700">受け取る報酬を確認してください。</p>@endif

        <section aria-labelledby="raid-reward-heading">
            <h2 id="raid-reward-heading" class="flex items-center gap-2 text-lg font-black text-slate-950">
                <img src="{{ asset('images/icon/icon_087.webp') }}" alt="" width="32" height="32" class="h-8 w-8 object-contain">報酬一覧
            </h2>
            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                <dl class="grid grid-cols-2 divide-x divide-slate-100 text-sm">
                    <div class="pr-3"><dt class="text-xs text-slate-600">有効出撃（参加報酬）</dt><dd class="mt-2 text-lg font-black tabular-nums text-slate-900 sm:text-2xl">{{ $rewardScreen['resolved_sorties'] }} / {{ $rewardScreen['participation_minimum_sorties'] }}回</dd></div>
                    <div class="pl-3 sm:pl-5"><dt class="text-xs text-slate-600">個人累計ダメージ</dt><dd class="mt-2 text-lg font-black tabular-nums text-sky-800 sm:text-2xl">{{ number_format($rewardScreen['personal_damage']) }}</dd></div>
                </dl>
                @if($event->status === 'active' && $rewardScreen['next_damage_goal'])
                    <div class="mt-4 border-t border-slate-100 pt-3" data-raid-next-goal>
                        <p class="text-xs font-bold text-slate-500">次のダメージ目標</p>
                        <p class="mt-1 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm text-slate-700">
                            <strong>{{ $rewardScreen['next_damage_goal']['display_label'] }}</strong>
                            <span class="font-bold tabular-nums text-sky-800">あと{{ number_format($rewardScreen['next_damage_goal']['remaining']) }}</span>
                        </p>
                    </div>
                @endif
            </div>
            <div id="raid-reward-conditions" class="mt-4 space-y-1 text-xs leading-relaxed text-slate-600">
                <p class="font-bold text-slate-700">
                @if($rewardScreen['participation_minimum_sorties'] !== $rewardScreen['minimum_sorties'])
                    参加報酬は有効出撃{{ $rewardScreen['participation_minimum_sorties'] }}回 ／ その他は有効出撃{{ $rewardScreen['minimum_sorties'] }}回
                @else
                    全報酬の共通条件：有効出撃{{ $rewardScreen['minimum_sorties'] }}回。
                @endif
                </p>
                <p>受取はイベント終了後の戦果確定から。個人累計に国家連携分は含みません。</p>
                @if($event->status !== 'completed')
                    <p>達成した報酬は「確定待ち」。順位は終了時に決まります。</p>
                @else
                    <p>届いた戦果を受け取ろう。報酬の受取期限はありません。</p>
                @endif
            </div>
            <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table class="w-full table-fixed border-collapse text-left text-sm" aria-describedby="raid-reward-conditions" data-raid-reward-table>
                    <caption class="sr-only">個人報酬・討伐報酬・順位称号の条件と受取状況</caption>
                    <colgroup><col><col class="w-24 sm:w-32"></colgroup>
                    <thead class="sr-only"><tr><th scope="col">報酬・達成条件</th><th scope="col">受取</th></tr></thead>
                    @foreach($rewardScreen['groups'] as $groupKey => $group)
                        <tbody class="divide-y divide-slate-100" data-raid-reward-group="{{ $groupKey }}">
                            <tr>
                                <th scope="rowgroup" colspan="2" class="border-y border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                                    <div class="flex flex-wrap items-center justify-between gap-1">
                                        <span class="font-black text-slate-800">{{ $group['label'] }}</span>
                                        <span class="text-xs font-normal text-slate-600">有効出撃{{ $groupKey === 'participation' ? $rewardScreen['participation_minimum_sorties'] : $rewardScreen['minimum_sorties'] }}回</span>
                                    </div>
                                </th>
                            </tr>
                            @foreach($group['rows'] as $row)
                                @include('nation-raid.partials.reward-row', ['row' => $row])
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </section>

        @if($event->status !== 'completed')
            <a href="{{ route('nation-raid.show', $event) }}" class="inline-flex min-h-11 items-center text-sm font-bold text-blue-700 underline">戦場へ戻る</a>
        @else
            @if($nationRewards->isNotEmpty())
                <details class="border-t border-slate-200 pt-4">
                    <summary class="cursor-pointer text-sm font-bold">各国の戦果</summary>
                    <p class="mt-2 text-xs text-slate-500">国家報酬は戦果確定時に国家へ届けられます。個人での受取は不要です。</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach($nationRewards as $reward)
                            <li>
                                {{ $reward->nation_name_snapshot }}：{{ $reward->reward_snapshot['label'] }}
                                @if(isset($reward->reward_snapshot['points']))
                                    {{ number_format($reward->reward_snapshot['points']) }}pt
                                @endif
                                （{{ $reward->status === 'claimed' ? '獲得済み' : '保管中' }}）
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @include('nation-raid.standings')
        @endif
    </div>
</x-layouts.facility>
