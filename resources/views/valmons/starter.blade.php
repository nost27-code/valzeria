<x-layouts.facility title="ともに旅をするヴァルモンを選ぼう" headerIconImage="images/valmon/egg01.webp" bgImage="images/bg-castle.webp" :showExit="false">
    <div class="py-6 w-full mx-auto sm:px-6 lg:px-8">
        <div class="mx-auto max-w-5xl space-y-4 sm:space-y-5" x-data="{ selected: null }">
            <div class="rounded-xl border border-sky-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-black tracking-wide text-sky-700">ヴァルモンとは？</div>
                <h2 class="mt-1 text-xl font-black text-slate-950">冒険者に寄り添い、探索を手伝ってくれる小さな相棒です。</h2>
                <p class="mt-3 text-sm font-bold leading-relaxed text-slate-600">
                    旅の途中で素材を見つけたり、ときには新しい仲間との出会いを運んできてくれます。
                    最初に選ぶ1体は、これからの冒険で一緒に歩く相棒になります。
                </p>
            </div>

            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-relaxed text-amber-800">
                最初の相棒を1体選んでください。性能差は大きくありません。見た目や雰囲気で選んで大丈夫です。
            </div>

            @if(session('error'))
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{{ session('error') }}</div>
            @endif

            <div class="grid gap-4 md:grid-cols-3">
                @foreach($starters as $starter)
                    @php
                        $assistText = match ($starter->valmon_key) {
                            'rapil' => '探索補助：薬草や回復素材を見つけやすい',
                            'pengle' => '探索補助：水・氷系素材を見つけやすい',
                            'leafy' => '探索補助：草木系素材を見つけやすい',
                            default => '探索補助：素材探しを手伝う',
                        };
                    @endphp
                    <div
                        role="button"
                        tabindex="0"
                        @click="selected = @js(['id' => $starter->id, 'name' => $starter->name, 'description' => $starter->description])"
                        @keydown.enter.prevent="selected = @js(['id' => $starter->id, 'name' => $starter->name, 'description' => $starter->description])"
                        @keydown.space.prevent="selected = @js(['id' => $starter->id, 'name' => $starter->name, 'description' => $starter->description])"
                        class="cursor-pointer rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-sky-300"
                    >
                        <div class="flex flex-col items-center text-center">
                            <div class="flex h-44 w-full items-center justify-center bg-white sm:h-52">
                                <img src="{{ $starter->imageUrl() ?: \App\Models\ValmonMaster::versionedAsset('images/valmon/valmon01.webp') }}" alt="{{ $starter->name }}" class="h-full max-h-52 w-full object-contain">
                            </div>
                            <div class="mt-3 w-full">
                                <div class="text-xs font-black text-slate-400">{{ $starter->silhouette_type }}</div>
                                <h2 class="mt-1 text-2xl font-black text-slate-950">{{ $starter->name }}</h2>
                            </div>
                        </div>
                        <p class="mt-4 min-h-20 text-sm font-bold leading-relaxed text-slate-600 md:text-center">{{ $starter->description }}</p>
                        <div class="mt-3 rounded-full border border-sky-100 bg-sky-50 px-3 py-1.5 text-center text-xs font-black text-sky-700">
                            {{ $assistText }}
                        </div>
                        <button
                            type="button"
                            class="mt-5 w-full rounded-lg border-2 border-slate-900 bg-white px-4 py-3 text-sm font-black text-slate-900 shadow-sm transition hover:bg-slate-900 hover:text-white">
                            この子にする
                        </button>
                    </div>
                @endforeach
            </div>

            <div x-show="selected" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
                <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                    <div class="text-lg font-black text-slate-950" x-text="`${selected?.name ?? ''}を最初の相棒にしますか？`"></div>
                    <p class="mt-3 text-sm font-bold leading-relaxed text-slate-600" x-text="`最初に選んだヴァルモンは、これからの冒険で一緒に旅をします。`"></p>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <button type="button" @click="selected = null" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-black text-slate-600 hover:bg-slate-50">
                            もう少し考える
                        </button>
                        <form method="POST" action="{{ route('valmons.starter.choose') }}">
                            @csrf
                            <input type="hidden" name="valmon_master_id" :value="selected?.id">
                            <button type="submit" class="w-full rounded-lg bg-emerald-700 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-emerald-800">
                                この子と旅を始める
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
