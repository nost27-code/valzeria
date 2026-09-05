<x-layouts.facility title="過去の戦果・未受取報酬" subtitle="国家対抗レイド" :exit-url="route('home')" exitLabel="街へ戻る">
    <div class="mx-auto max-w-3xl space-y-6 pb-6" data-nation-raid-history>
        <a href="{{ route('nation-raid.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-blue-700 underline">国家対抗レイドへ戻る</a>
        <section id="pending-rewards" aria-labelledby="pending-rewards-heading">
            <h1 id="pending-rewards-heading" class="text-lg font-black">未受取報酬 <span class="text-sm text-slate-500">{{ number_format($pendingRewards->total()) }}件</span></h1>
            <p class="mt-2 text-xs text-slate-600">戦利品を預かっています。受取期限はありません。</p>
            <div class="mt-3 divide-y divide-slate-200">
                @forelse($pendingRewards as $reward)
                    <article class="py-4">
                        <p class="break-words text-xs text-slate-500">{{ $reward->event->name }} · {{ $reward->event->ends_at->format('Y/n/j') }} 終了</p>
                        <h2 class="mt-1 break-words text-sm font-bold">{{ $reward->reward_snapshot['label'] }}</h2>
                        <a href="{{ route('nation-raid.rewards', $reward->event) }}#raid-reward-{{ $reward->id }}" class="mt-1 inline-flex min-h-11 items-center text-sm font-bold text-blue-700 underline">報酬を確認する</a>
                    </article>
                @empty
                    <p class="py-4 text-sm text-slate-600">未受取の報酬はありません。</p>
                @endforelse
            </div>
            {{ $pendingRewards->links() }}
        </section>
        <section id="past-results" class="border-t border-slate-200 pt-5" aria-labelledby="past-results-heading">
            <h2 id="past-results-heading" class="text-lg font-black">過去の戦果</h2>
            <div class="mt-3 divide-y divide-slate-200">
                @forelse($history as $entry)
                    <article class="py-4">
                        <h3 class="break-words font-bold">{{ $entry['event']->name }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ $entry['event']->starts_at->format('Y/n/j') }} 〜 {{ $entry['event']->ends_at->format('Y/n/j') }}</p>
                        @if($entry['record_unavailable'])
                            <p class="mt-3 text-sm text-amber-800">戦果の記録を確認できません。時間をおいて確認してください。</p>
                        @elseif($entry['record'] !== null)
                            <p class="mt-2 break-words text-xs text-slate-600">{{ $entry['record']['name'] }} · {{ $entry['nation_name'] }}</p>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                <div><dt class="text-xs text-slate-500">個人累計ダメージ</dt><dd class="font-bold tabular-nums">{{ number_format($entry['record']['damage']) }}</dd></div>
                                <div><dt class="text-xs text-slate-500">個人累計順位</dt><dd class="font-bold">{{ $entry['record']['rank'] === null ? '—' : $entry['record']['rank'].'位' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">1行動最大ダメージ</dt><dd class="font-bold tabular-nums">{{ number_format($entry['record']['max_action_damage']) }}</dd></div>
                                <div><dt class="text-xs text-slate-500">出撃回数</dt><dd class="font-bold">{{ $entry['record']['resolved_sorties'] }}回</dd></div>
                            </dl>
                        @else
                            <p class="mt-3 text-sm text-slate-600">確定した出撃記録はありません。</p>
                        @endif
                        @unless($entry['record_unavailable'])
                            <a href="{{ route('nation-raid.rewards', $entry['event']) }}" class="mt-2 inline-flex min-h-11 items-center text-sm font-bold text-blue-700 underline">戦果・報酬を見る</a>
                        @endunless
                    </article>
                @empty
                    <p class="py-4 text-sm text-slate-600">確定した戦果はまだありません。</p>
                @endforelse
            </div>
            {{ $history->links() }}
        </section>
    </div>
</x-layouts.facility>
