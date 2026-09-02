<nav aria-label="Valzeria Lab" class="mb-6 overflow-x-auto border-b border-slate-300">
    <div class="flex min-w-max gap-1">
        @foreach([
            'admin.valzeria-lab.replay' => '再現',
            'admin.valzeria-lab.world' => '世界グラフ',
            'admin.valzeria-lab.adventurer' => '仮想冒険者',
        ] as $routeName => $label)
            @php $active = request()->routeIs($routeName); @endphp
            <a href="{{ route($routeName) }}"
               @if($active) aria-current="page" @endif
               class="border-b-2 px-4 py-3 text-sm font-black transition {{ $active ? 'border-amber-500 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</nav>
