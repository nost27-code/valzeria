<x-layouts.facility title="戦技更新履歴" bgImage="images/bg-castle.webp" :showExit="false" :showFacilityHeader="false">
    <div class="mx-auto max-w-[680px] space-y-4 px-3 pb-12" data-job-art-update-history>
        <header class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <a href="{{ route('job-arts.index') }}" class="inline-flex min-h-10 items-center text-xs font-black text-indigo-700">← 戦技セットへ</a>
            <h1 class="mt-3 text-xl font-black text-slate-950">戦技更新履歴</h1>
            <p class="mt-2 text-xs font-bold leading-6 text-slate-600">戦技の変更前後と調整理由を、新しい順にまとめています。未習得の戦技も確認できます。</p>
            <p class="mt-2 text-[11px] font-bold leading-5 text-slate-500">未読マークは、戦技セットで各技の「効果解説を見る」を開くと解除されます。</p>
        </header>

        @forelse($updates as $group)
            <section class="space-y-3" aria-label="{{ $group['date'] }}の戦技更新">
                <h2 class="text-sm font-black text-slate-700">{{ $group['date'] }}の更新記録 <span class="text-xs text-slate-500">（{{ $group['entries']->count() }}件）</span></h2>
                @foreach($group['entries'] as $entry)
                    <article class="min-w-0 break-words rounded-xl border border-amber-200 bg-white p-4 shadow-sm" data-job-art-history-entry>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-base font-black text-slate-950">{{ $entry['name'] }}</h3>
                            @if($entry['status'] === 'published')
                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-black text-emerald-700">公開済み</span>
                            @else
                                <span class="rounded-full bg-amber-50 px-2 py-1 text-[10px] font-black text-amber-800">公開準備中</span>
                            @endif
                        </div>
                        @if($entry['published_at'])
                            <p class="mt-1 text-[10px] font-bold text-slate-500">公開日：{{ $entry['published_at'] }}</p>
                        @endif
                        <div class="mt-3 space-y-3 text-xs font-bold leading-6 text-slate-700">
                            <div>
                                <h4 class="font-black text-slate-500">変更前</h4>
                                <p>{{ $entry['before'] }}</p>
                            </div>
                            <div>
                                <h4 class="font-black text-indigo-700">変更後</h4>
                                <p data-job-art-history-after>@include('job-arts.partials.effect-text', ['text' => $entry['after']])</p>
                            </div>
                            <div>
                                <h4 class="font-black text-slate-500">調整理由</h4>
                                <p>{{ $entry['reason'] }}</p>
                            </div>
                            <p class="border-t border-slate-100 pt-2 text-[11px] text-slate-500">{{ $entry['unchanged'] }}</p>
                        </div>
                    </article>
                @endforeach
            </section>
        @empty
            <p class="rounded-xl border border-slate-200 bg-white p-4 text-sm font-bold text-slate-500">戦技の更新記録はまだありません。</p>
        @endforelse

        @if($updates->hasPages())
            <nav class="flex items-center justify-between gap-3 text-xs font-black" aria-label="戦技更新履歴のページ">
                @if($updates->previousPageUrl())
                    <a href="{{ $updates->previousPageUrl() }}" class="rounded-lg border border-slate-200 bg-white px-3 py-3 text-indigo-700">← 新しい記録</a>
                @else
                    <span></span>
                @endif
                <span>{{ $updates->currentPage() }} / {{ $updates->lastPage() }}</span>
                @if($updates->nextPageUrl())
                    <a href="{{ $updates->nextPageUrl() }}" class="rounded-lg border border-slate-200 bg-white px-3 py-3 text-indigo-700">過去の記録 →</a>
                @else
                    <span></span>
                @endif
            </nav>
        @endif
        <a href="{{ route('job-arts.index') }}" class="block rounded-xl bg-indigo-600 px-4 py-3 text-center text-sm font-black text-white">戦技セットへ戻る</a>
    </div>
</x-layouts.facility>
