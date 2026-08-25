<div class="mt-2 rounded-xl border border-stone-200 bg-stone-50 p-2">
    <p class="px-1 pb-2 text-xs font-bold text-stone-500">全{{ count($emblems) }}種から選べます</p>
    <div class="max-h-80 overflow-y-auto overscroll-contain pr-1" data-nation-emblem-picker="{{ $wireModel }}">
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
            @foreach($emblems as $key => $emblem)
                @if($selectionAction)
                    <button type="button" wire:key="{{ $wireModel }}-{{ $key }}" wire:click="{{ $selectionAction }}('{{ $key }}')" wire:loading.attr="disabled" wire:target="{{ $selectionAction }}" aria-pressed="{{ $selectedEmblemKey === $key ? 'true' : 'false' }}" class="cursor-pointer rounded-lg border p-1.5 text-center transition disabled:opacity-50 {{ $selectedEmblemKey === $key ? 'border-amber-500 bg-amber-50 ring-2 ring-amber-300' : 'border-stone-200 bg-white hover:border-amber-300' }}">
                        <img src="{{ asset($emblem['path']) }}" alt="{{ $emblem['alt'] }}" width="128" height="128" loading="lazy" decoding="async" class="mx-auto h-14 w-14 object-contain">
                        <span class="mt-1 block text-[10px] font-black text-stone-700">No.{{ substr($key, -3) }}</span>
                    </button>
                @else
                    <label wire:key="{{ $wireModel }}-{{ $key }}" class="cursor-pointer rounded-lg border p-1.5 text-center transition {{ $selectedEmblemKey === $key ? 'border-amber-500 bg-amber-50 ring-2 ring-amber-300' : 'border-stone-200 bg-white hover:border-amber-300' }}">
                        <input type="radio" name="{{ $wireModel }}" wire:model.live="{{ $wireModel }}" value="{{ $key }}" class="sr-only">
                        <img src="{{ asset($emblem['path']) }}" alt="{{ $emblem['alt'] }}" width="128" height="128" loading="lazy" decoding="async" class="mx-auto h-14 w-14 object-contain">
                        <span class="mt-1 block text-[10px] font-black text-stone-700">No.{{ substr($key, -3) }}</span>
                    </label>
                @endif
            @endforeach
        </div>
    </div>
</div>
