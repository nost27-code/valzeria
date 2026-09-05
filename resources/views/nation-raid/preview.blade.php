<x-layouts.facility title="国家対抗レイド" subtitle="黒天竜討伐戦の事前案内" :exit-url="route('home')" exitLabel="街へ戻る">
    <div class="mx-auto max-w-3xl space-y-6 pb-6" x-data="{}" data-nation-raid-preview>
        <nav class="grid grid-cols-4 gap-1 rounded-xl border border-slate-200 bg-white p-1 text-center text-xs font-bold sm:text-sm" aria-label="レイド事前案内メニュー">
            @foreach(['top' => 'TOP', 'battle' => '戦闘', 'rankings' => 'ランキング', 'rewards' => '報酬'] as $tab => $label)
                @if($tab === 'battle')
                    <button type="button" @click="$refs.preparing.showModal()" aria-haspopup="dialog" class="min-h-11 rounded-lg px-1 text-slate-600 hover:bg-slate-100" data-raid-preview-battle>戦闘</button>
                @else
                    <a href="{{ route('nation-raid.preview', ['page' => $tab === 'top' ? null : $tab]) }}"
                        @if($page === $tab) aria-current="page" @endif
                        @class(['flex min-h-11 items-center justify-center rounded-lg px-1 focus-visible:outline-2 focus-visible:outline-sky-700', 'bg-slate-800 text-white' => $page === $tab, 'text-slate-600 hover:bg-slate-100' => $page !== $tab])>{{ $label }}</a>
                @endif
            @endforeach
        </nav>

        <header class="border-b border-slate-200 pb-5">
            <p class="text-sm font-bold text-sky-800">開催準備中 <span class="ml-2 font-normal text-slate-500">開催日未定</span></p>
            <h1 class="mt-2 text-xl font-black leading-relaxed text-slate-900">{{ $bossName }}</h1>
            <p class="mt-2 text-sm leading-relaxed text-slate-600">黒天竜との決戦に備えよう。現在は事前案内のみ公開しています。</p>
        </header>

        @if($page === 'top')
            <section aria-label="レイドボスの紹介" class="text-center">
                <img src="{{ asset($bossImage) }}" alt="第一形態のヴァルグレイド" width="320" height="320" class="mx-auto h-64 w-64 max-w-full object-contain sm:h-80 sm:w-80">
                <h2 class="mt-4 text-lg font-black text-slate-900">国の仲間と、全冒険者と。黒天竜に挑もう。</h2>
                <p class="mt-3 text-sm leading-loose text-slate-600">全プレイヤーで一体のボスのHPを削り、各国の総ダメージを競う討伐戦。<br class="hidden sm:block">倒れるたびに再臨する黒天竜へ、仲間と力を合わせて立ち向かおう。</p>
                <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <button type="button" @click="$refs.preparing.showModal()" aria-haspopup="dialog" class="min-h-12 rounded-xl bg-slate-800 px-6 py-3 text-sm font-bold text-white hover:bg-slate-700">ヴァルグレイドに挑む <span class="ml-1 text-xs font-normal">準備中</span></button>
                    <a href="{{ route('nation-raid.preview', ['page' => 'rewards']) }}" class="flex min-h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-sky-800 hover:bg-slate-50">予定報酬を見る</a>
                </div>
            </section>
        @elseif($page === 'rankings')
            <section class="rounded-2xl border border-slate-200 bg-white p-6 text-center" aria-labelledby="raid-preview-ranking-heading">
                <h2 id="raid-preview-ranking-heading" class="text-lg font-black text-slate-900">国家ランキング</h2>
                <p class="mt-4 font-bold text-sky-800">開催後に集計開始</p>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">各国の総ダメージと国家連携の状況が、ここに刻まれます。</p>
            </section>
        @else
            <section aria-labelledby="raid-preview-reward-heading">
                <h2 id="raid-preview-reward-heading" class="text-lg font-black text-slate-900">予定報酬一覧</h2>
                <div id="raid-preview-reward-conditions" class="mt-3 space-y-1 text-xs leading-relaxed text-slate-600">
                    <p class="font-bold text-slate-700">参加報酬は有効出撃{{ $rewardScreen['participation_minimum_sorties'] }}回 ／ その他は有効出撃{{ $rewardScreen['minimum_sorties'] }}回</p>
                    <p>受取は開催終了後の戦果確定から。開催前は受け取れません。</p>
                    <p>個人累計ダメージに国家連携分は含みません。順位は終了時に決まります。</p>
                    <p>報酬・条件は予定です。正式な内容は開催時の案内をご確認ください。</p>
                </div>
                <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <table class="w-full table-fixed border-collapse text-left text-sm" aria-describedby="raid-preview-reward-conditions" data-raid-preview-reward-table>
                        <caption class="sr-only">開催前の予定報酬と達成条件</caption>
                        <colgroup><col><col class="w-20 sm:w-28"></colgroup>
                        <thead class="sr-only"><tr><th scope="col">予定報酬・達成条件</th><th scope="col">開催状況</th></tr></thead>
                        @foreach($rewardScreen['groups'] as $groupKey => $group)
                            <tbody class="divide-y divide-slate-100" data-raid-reward-group="{{ $groupKey }}">
                                <tr><th scope="rowgroup" colspan="2" class="border-y border-slate-200 bg-slate-50 px-4 py-3 font-black text-slate-800 sm:px-5">{{ $group['label'] }}</th></tr>
                                @foreach($group['rows'] as $row)
                                    @include('nation-raid.partials.reward-row', ['row' => $row])
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </section>
        @endif

        <dialog x-ref="preparing" class="m-auto w-[calc(100%-2rem)] max-w-sm rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-900/40" aria-labelledby="raid-preparing-title" aria-describedby="raid-preparing-description" data-raid-preparing-dialog>
            <h2 id="raid-preparing-title" class="text-lg font-black">開催準備中</h2>
            <p id="raid-preparing-description" class="mt-3 text-sm leading-relaxed text-slate-600">黒天竜との戦いは、まだ始まっていません。開戦の知らせをお待ちください。</p>
            <p class="mt-2 text-xs text-slate-500">探索力は消費されません。</p>
            <form method="dialog" class="mt-5"><button type="submit" class="min-h-11 w-full rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white" autofocus>閉じる</button></form>
        </dialog>
    </div>
</x-layouts.facility>
