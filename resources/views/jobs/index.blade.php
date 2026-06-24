<x-layouts.app>
    <div class="max-w-7xl mx-auto p-4 flex flex-col gap-4 text-sm min-h-screen">
        <!-- ヘッダー -->
        <div class="bg-slate-800 text-white rounded-lg shadow-sm border border-slate-700 flex-shrink-0">
            <div class="p-3 flex justify-between items-center border-b border-slate-700 bg-slate-900 rounded-t-lg">
                <h1 class="font-bold text-xl tracking-wider text-yellow-400 flex items-center gap-2">
                    <img src="{{ asset('images/icon/icon_031.webp') }}" alt="" class="w-7 h-7 object-contain"> 転職所
                </h1>
                <div class="text-right text-[10px] text-slate-400">
                    職業を変え、新たな成長を。
                </div>
            </div>
            
            <div class="p-3 bg-slate-800 text-[11px] leading-relaxed text-slate-300 rounded-b-lg">
                「よく来たな。ここではレベル100に達した強者のみ、新たな職業へと生まれ変わることができる。」
            </div>
        </div>

        @if(session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
            <span class="block sm:inline font-bold">{{ session('error') }}</span>
        </div>
        @endif

        <div class="flex flex-col md:flex-row gap-4">
            <!-- 左カラム：現在のステータス -->
            <div class="w-full md:w-1/3 flex flex-col gap-4">
                <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-3 bg-slate-50 border-b border-slate-200 font-bold text-slate-700">
                        現在の能力
                    </div>
                    <div class="p-3 space-y-2 text-[11px]">
                        <div class="flex justify-between items-center border-b pb-1">
                            <span class="text-slate-500">名前</span>
                            <span class="font-bold text-slate-800">{{ $character->name }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b pb-1">
                            <span class="text-slate-500">現在の職業</span>
                            <span class="font-bold text-amber-700">{{ optional($character->jobClass)->name ?? 'なし' }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b pb-1">
                            <span class="text-slate-500">レベル</span>
                            <span class="font-bold {{ $character->level >= 100 ? 'text-yellow-600' : 'text-slate-800' }}">{{ $character->level }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b pb-1">
                            <span class="text-slate-500">転職回数</span>
                            <span class="font-bold text-slate-800">{{ $character->reincarnation_count }} 回</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 pt-2">
                            <div class="flex justify-between"><span class="text-slate-400">HP</span><span class="font-bold text-slate-700">{{ $character->hp_base }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">攻撃</span><span class="font-bold text-slate-700">{{ $character->attack_base }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">防御</span><span class="font-bold text-slate-700">{{ $character->defense_base }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">敏捷</span><span class="font-bold text-slate-700">{{ $character->speed_base }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">魔力</span><span class="font-bold text-slate-700">{{ $character->magic_base }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">運</span><span class="font-bold text-slate-700">{{ $character->luck_base }}</span></div>
                        </div>
                    </div>
                </div>

                @if($character->level < 100)
                <div class="bg-red-50 text-red-700 border border-red-200 rounded-lg p-4 shadow-sm">
                    <h3 class="font-bold mb-1">条件未達成</h3>
                    <p class="text-[11px] leading-relaxed">転職を行うには、レベルが100に達している必要があります。まずは戦闘を重ね、レベルを上げてください。</p>
                </div>
                @endif
            </div>

            <!-- 右カラム：職業一覧 -->
            <div class="w-full md:w-2/3">
                <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden h-full flex flex-col">
                    <div class="p-3 bg-slate-50 border-b border-slate-200 font-bold text-slate-700">
                        転職可能な職業
                    </div>
                    
                    <div class="p-4 grid grid-cols-1 gap-5">
                        @foreach($jobs as $job)
                        <div class="group relative rounded-xl transition-all duration-300 {{ $character->level >= 100 ? 'bg-white shadow-sm hover:shadow-md hover:-translate-y-1' : 'bg-slate-50 opacity-80' }}">
                            
                            <!-- 装飾的な左のアクセントライン -->
                            <div class="absolute left-0 top-0 bottom-0 w-1.5 rounded-l-xl {{ $character->current_job_id === $job->id ? 'bg-gradient-to-b from-amber-500 to-purple-500' : 'bg-gradient-to-b from-slate-300 to-slate-200 group-hover:from-amber-400 group-hover:to-blue-400' }} transition-colors duration-300"></div>

                            <div class="p-4 pl-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-1">
                                        <h3 class="text-lg font-bold text-slate-800 tracking-wide group-hover:text-amber-700 transition-colors">
                                            {{ $job->name }}
                                        </h3>
                                        @if($character->current_job_id === $job->id)
                                            <span class="bg-amber-100 text-amber-700 text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 ring-amber-200/50 shadow-sm">
                                                現在の職業
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-slate-500 mb-3 leading-relaxed">
                                        {{ $job->description }}
                                    </p>
                                    
                                    <!-- 成長傾向タグ群 -->
                                    <div class="flex flex-wrap gap-2">
                                        <div class="bg-slate-100/80 px-2 py-1 rounded-md flex items-center gap-1.5">
                                            <span class="text-slate-400 font-bold text-[10px]">HP</span>
                                            <span class="text-slate-700 font-medium text-[11px]">+{{ $job->hp_growth_min }}〜{{ $job->hp_growth_max }}</span>
                                        </div>
                                        <div class="bg-slate-100/80 px-2 py-1 rounded-md flex items-center gap-1.5">
                                            <span class="text-slate-400 font-bold text-[10px]">攻撃</span>
                                            <span class="text-slate-700 font-medium text-[11px]">+{{ $job->attack_growth_min }}〜{{ $job->attack_growth_max }}</span>
                                        </div>
                                        <div class="bg-slate-100/80 px-2 py-1 rounded-md flex items-center gap-1.5">
                                            <span class="text-slate-400 font-bold text-[10px]">防御</span>
                                            <span class="text-slate-700 font-medium text-[11px]">+{{ $job->defense_growth_min }}〜{{ $job->defense_growth_max }}</span>
                                        </div>
                                        <div class="bg-slate-100/80 px-2 py-1 rounded-md flex items-center gap-1.5">
                                            <span class="text-slate-400 font-bold text-[10px]">敏捷</span>
                                            <span class="text-slate-700 font-medium text-[11px]">+{{ $job->speed_growth_min }}〜{{ $job->speed_growth_max }}</span>
                                        </div>
                                        <div class="bg-slate-100/80 px-2 py-1 rounded-md flex items-center gap-1.5">
                                            <span class="text-slate-400 font-bold text-[10px]">魔力</span>
                                            <span class="text-slate-700 font-medium text-[11px]">+{{ $job->magic_growth_min }}〜{{ $job->magic_growth_max }}</span>
                                        </div>
                                        <div class="bg-slate-100/80 px-2 py-1 rounded-md flex items-center gap-1.5">
                                            <span class="text-slate-400 font-bold text-[10px]">運</span>
                                            <span class="text-slate-700 font-medium text-[11px]">+{{ $job->luck_growth_min }}〜{{ $job->luck_growth_max }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-2 md:mt-0 md:ml-4 flex-shrink-0">
                                    @if($character->level >= 100)
                                        <a href="{{ route('jobs.confirm', ['job' => $job->id]) }}" class="inline-flex items-center justify-center bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-6 rounded-lg text-xs shadow-md hover:shadow-lg transition-all duration-300 w-full md:w-auto">
                                            この職業を選ぶ
                                        </a>
                                    @else
                                        <button disabled class="bg-slate-200 text-slate-400 font-bold py-2 px-6 rounded-lg text-xs cursor-not-allowed w-full md:w-auto">
                                            レベル不足
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- アクションエリア -->
        <div class="mt-8 mb-4 flex justify-center">
            <x-back-button href="{{ route('home') }}" />
        </div>
    </div>
</x-layouts.app>
