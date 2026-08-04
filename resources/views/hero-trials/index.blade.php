<x-layouts.facility
    title="英雄試練殿"
    subtitle="魔王城ヴァルゼリア"
    headerIconImage="images/symbol/hero_trial_hall.webp"
    bgImage="images/bg-battle.webp"
    pageBackgroundClass="bg-slate-950"
    exitLabel="魔王城へ戻る"
>
    <div class="mx-auto w-full max-w-4xl space-y-4 px-3 py-4 sm:px-6">
        <section class="rounded-xl border border-amber-300/70 bg-slate-950 px-4 py-4 text-center text-white shadow-lg">
            <div class="text-xs font-black tracking-[0.25em] text-amber-300">HERO TRIAL HALL</div>
            <h1 class="mt-1 text-xl font-black">挑む試練を選べ</h1>
            <p class="mt-2 text-xs leading-relaxed text-slate-300">冠位を極めた者だけに、英雄へ至る道が姿を現す。</p>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach($trials as $trial)
                <article class="relative overflow-hidden rounded-xl border border-amber-300/60 bg-white shadow-lg">
                    @if(! empty($trial['bg_image']))
                        <div class="absolute inset-0 bg-cover bg-right opacity-15" style="background-image: url('{{ asset('images/'.ltrim($trial['bg_image'], '/')) }}');"></div>
                    @endif

                    <div class="relative flex h-full flex-col p-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full border border-amber-300 bg-white/90 p-1 shadow">
                                <img src="{{ asset('images/'.ltrim($trial['symbol_image'], '/')) }}" alt="{{ $trial['name'] }}" class="h-full w-full object-contain">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[10px] font-black tracking-wider text-amber-700">{{ $trial['badge'] ?? '英雄試練' }}</div>
                                <h2 class="text-lg font-black text-slate-950">{{ $trial['name'] }}</h2>
                                <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $trial['desc'] }}</p>
                            </div>
                        </div>

                        <div class="mt-auto pt-4">
                            @if(! empty($trial['is_post']))
                                <form action="{{ route($trial['route'], $trial['params'] ?? []) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                    @csrf
                                    <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full items-center justify-center rounded-lg border-2 border-red-950 bg-red-900 px-4 py-2 text-sm font-black text-white shadow transition hover:bg-red-800 active:scale-[0.98] disabled:cursor-wait disabled:opacity-70">
                                        <span x-show="!submitting">{{ $trial['action'] }}</span>
                                        <span x-show="submitting" style="display: none;">試練へ向かっている...</span>
                                    </button>
                                </form>
                            @else
                                @if(! empty($trial['route']))
                                    <a href="{{ route($trial['route'], $trial['params'] ?? []) }}" class="inline-flex w-full items-center justify-center rounded-lg border-2 border-amber-700 bg-amber-500 px-4 py-2 text-sm font-black text-slate-950 shadow transition hover:bg-amber-400 active:scale-[0.98]">
                                        {{ $trial['action'] }}
                                    </a>
                                @else
                                    <button type="button" disabled class="inline-flex w-full cursor-not-allowed items-center justify-center rounded-lg border-2 border-slate-400 bg-slate-200 px-4 py-2 text-sm font-black text-slate-500 shadow">
                                        {{ $trial['action'] }}
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</x-layouts.facility>
