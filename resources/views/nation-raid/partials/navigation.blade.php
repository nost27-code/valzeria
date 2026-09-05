<nav class="grid grid-cols-4 gap-1 rounded-xl border border-slate-200 bg-white p-1 text-center text-xs font-bold sm:text-sm" aria-label="レイドメニュー" data-nation-raid-navigation>
    @foreach(['top' => 'TOP', 'show' => '戦闘', 'rankings' => 'ランキング', 'rewards' => '報酬'] as $page => $label)
        @if($page === 'show' && ($finished ?? false))
            <span aria-disabled="true" class="flex min-h-11 items-center justify-center rounded-lg text-slate-400">戦闘終了</span>
        @else
            <a href="{{ route('nation-raid.'.$page, $eventId) }}"
                @if($active === $page) aria-current="page" @endif
                @class(['flex min-h-11 items-center justify-center rounded-lg px-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-sky-700',
                    'bg-slate-800 text-white' => $active === $page, 'text-slate-600 hover:bg-slate-100' => $active !== $page])>{{ $label }}</a>
        @endif
    @endforeach
</nav>
