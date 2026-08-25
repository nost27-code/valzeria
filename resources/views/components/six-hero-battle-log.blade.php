@props([
    'logs' => [],
    'title' => '戦闘ログ',
    'titleId' => 'six-hero-battle-log-title',
    'description' => null,
    'scrollable' => false,
])

{{-- logs must be sanitized by SixHeroBattleResultPresenter::styledBattleLogs(). --}}
<section {{ $attributes->class(['rounded-lg border border-slate-200 bg-white']) }} data-battle-log aria-labelledby="{{ $titleId }}">
    <h2 id="{{ $titleId }}" class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-black text-slate-800">{{ $title }}</h2>
    @if($description)
        <p class="border-b border-slate-200 bg-white px-4 py-2 text-xs font-bold leading-relaxed text-slate-500">{{ $description }}</p>
    @endif
    <div @class([
        'space-y-3 px-3 py-4 sm:px-4',
        'max-h-[560px] overflow-y-auto' => $scrollable,
    ])>
        @forelse($logs as $log)
            <p class="battle-log-entry whitespace-pre-line break-words font-mono text-sm leading-loose text-slate-700 sm:text-base" data-battle-log-line>{!! $log !!}</p>
        @empty
            <p class="font-bold text-rose-700">戦闘ログを表示できませんでした。</p>
        @endforelse
    </div>
</section>
