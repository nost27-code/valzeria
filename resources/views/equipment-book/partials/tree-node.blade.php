@php
    $rankClass = match ($node['rank']) {
        'EPIC' => 'border-rose-400 bg-rose-50 text-rose-800',
        'SSS' => 'border-orange-400 bg-orange-50 text-orange-800',
        'SS' => 'border-violet-400 bg-violet-50 text-violet-800',
        'S' => 'border-amber-400 bg-amber-50 text-amber-900',
        'A' => 'border-red-300 bg-red-50 text-red-800',
        'B' => 'border-blue-300 bg-blue-50 text-blue-800',
        'C' => 'border-emerald-300 bg-emerald-50 text-emerald-800',
        default => 'border-slate-300 bg-slate-50 text-slate-700',
    };
@endphp

<li>
    <button
        type="button"
        @click='detail = @json($node["detail"])'
        class="group relative w-24 rounded-lg border-2 p-1.5 text-left shadow-md transition hover:-translate-y-0.5 hover:shadow-lg sm:w-32 sm:rounded-xl sm:p-2 {{ $rankClass }} {{ $node['discovered'] ? '' : 'opacity-70 grayscale' }}"
    >
        <span class="absolute -top-2.5 left-1/2 -translate-x-1/2 rounded-full border border-current bg-white px-1.5 py-px text-[9px] font-black shadow-sm sm:-top-3 sm:px-2 sm:py-0.5 sm:text-[10px]">
            {{ $node['rank'] ?: '？' }}
        </span>
        <div class="mt-1 flex h-14 items-center justify-center rounded-md bg-white/80 sm:h-20 sm:rounded-lg">
            <img src="{{ asset($node['image']) }}" alt="" class="h-11 w-11 object-contain sm:h-16 sm:w-16 {{ $node['discovered'] ? '' : 'opacity-40' }}">
        </div>
        <div class="mt-1 min-h-8 text-center text-[10px] font-black leading-4 sm:mt-2 sm:min-h-10 sm:text-xs sm:leading-5">
            {{ $node['name'] }}
        </div>
        <div class="mt-0.5 truncate text-center text-[9px] font-bold sm:mt-1 sm:text-[10px] {{ $node['discovered'] ? 'text-emerald-700' : 'text-slate-500' }}">
            @if($node['discovered'])
                発見済み{{ $node['owned_count'] > 0 ? '・所持'.$node['owned_count'] : '' }}
            @else
                未発見
            @endif
        </div>
    </button>

    @if(($node['children'] ?? []) !== [])
        <ul>
            @foreach($node['children'] as $child)
                @include('equipment-book.partials.tree-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
