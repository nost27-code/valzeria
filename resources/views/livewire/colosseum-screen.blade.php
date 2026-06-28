<div class="space-y-6">
    <!-- ヘッダー：自分のステータス -->
    <div class="rounded-lg p-6 sm:p-8 relative overflow-hidden group min-h-[200px] flex items-center">
        <!-- 実際の背景画像 -->
        <div class="absolute inset-0 z-0 transition-transform duration-700 group-hover:scale-105" 
             style="background-image: url('{{ asset('images/facilities/02_闘技場.webp') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;"></div>

        <div class="flex items-center justify-between w-full relative z-10">
            <div class="min-w-0 pl-2 sm:pl-4">
                <h2 class="text-4xl sm:text-5xl md:text-6xl font-black text-white tracking-widest" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.8), -2px -2px 0 #000, 2px -2px 0 #000, -2px 2px 0 #000, 2px 2px 0 #000;">闘技場</h2>
                <p class="text-white font-bold text-sm sm:text-base md:text-lg mt-2 sm:mt-3 tracking-wide whitespace-nowrap" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.8), -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000;">1位を目指して上位ランカーに挑め！</p>
            </div>
            <div class="text-right pr-2 sm:pr-4 shrink-0">
                <div class="text-sm sm:text-base md:text-lg text-white font-bold mb-1 tracking-wider whitespace-nowrap" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.8), -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000;">現在の順位</div>
                <div class="text-5xl sm:text-6xl md:text-7xl lg:text-8xl font-black text-amber-400 whitespace-nowrap" style="text-shadow: 3px 3px 6px rgba(0,0,0,0.9), -2px -2px 0 #000, 2px -2px 0 #000, -2px 2px 0 #000, 2px 2px 0 #000;">
                    {{ $myRanking->rank ?? '---' }} <span class="text-2xl sm:text-3xl md:text-4xl text-white font-bold" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.8), -2px -2px 0 #000, 2px -2px 0 #000, -2px 2px 0 #000, 2px 2px 0 #000;">位</span>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- 左カラム：挑戦エリア -->
        <div class="space-y-6">
            <!-- 挑める相手リスト -->
            <div class="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden">
                <div class="bg-slate-50 px-4 py-3 border-b border-slate-200">
                    <h3 class="font-bold text-slate-700 flex items-center">
                        <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="w-4 h-4 object-contain mr-2"> ランク戦に挑む
                    </h3>
                </div>
                <div class="p-6 text-center">
                    @if(session('error'))
                        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                            {{ session('error') }}
                        </div>
                    @endif
                    @if(count($targetRankings) > 0)
                        <p class="text-sm text-slate-500 mb-4">上位ランカーにランダムで挑戦します。負けても順位は下がりません。</p>
                        @if(!empty($storageIsFull))
                            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                                {!! $storageFullMessage !!}
                            </div>
                        @endif
                        @if($rankBattleCooldownRemaining > 0)
                            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                                次のランク戦まであと{{ $rankBattleCooldownRemaining }}秒
                            </div>
                        @endif
                        @unless(!empty($storageIsFull))
                            <form action="{{ route('battle.pvp_random') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                @csrf
                                <button type="submit" x-bind:disabled="submitting || {{ $rankBattleCooldownRemaining > 0 ? 'true' : 'false' }}" class="inline-flex items-center justify-center gap-2 px-8 py-3 bg-red-500 text-white font-bold rounded-lg hover:bg-red-600 transition shadow-md disabled:opacity-50 disabled:cursor-wait w-full max-w-xs mx-auto text-lg">
                                    <x-loading-spinner x-show="submitting" style="display: none;" />
                                    <span x-show="!submitting">{{ $rankBattleCooldownRemaining > 0 ? '待機中' : 'ランク戦に挑む' }}</span>
                                    <span x-show="submitting" style="display: none;">マッチング中...</span>
                                </button>
                            </form>
                        @endunless
                    @else
                        @if($myRanking->rank === 1)
                            <div class="mb-2 flex justify-center"><img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="w-12 h-12 object-contain"></div>
                            <div class="font-bold text-amber-500">あなたは現在1位です！</div>
                            <div class="text-sm mt-1 text-slate-500">これ以上挑める上位ランカーはいません。防衛を頑張りましょう！</div>
                        @else
                            <p class="text-slate-500">挑める相手が見つかりません。</p>
                        @endif
                    @endif
                </div>
            </div>

            <!-- トップランカー -->
            <div class="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden">
                <div class="bg-amber-50 px-4 py-3 border-b border-amber-100">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-bold text-amber-700 flex items-center">
                            <img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="w-4 h-4 object-contain mr-2"> 闘技場トップランカー
                        </h3>
                        <a href="{{ route('colosseum.ranking') }}" wire:navigate class="text-xs font-bold text-amber-700 underline underline-offset-2 hover:text-amber-900">
                            さらに順位を見る
                        </a>
                    </div>
                </div>
                <div class="p-0">
                    @foreach($topRankings as $top)
                        <div class="flex items-center gap-3 p-3 border-b border-slate-100 last:border-0">
                            <div class="w-8 shrink-0 text-center text-xl font-black {{ $top['rank'] === 1 ? 'text-amber-500' : ($top['rank'] === 2 ? 'text-slate-400' : 'text-amber-700') }}">
                                {{ $top['rank'] }}
                            </div>
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                @if(!empty($top['image_path']))
                                    <div class="h-10 w-10 shrink-0 overflow-hidden rounded border border-amber-100 bg-slate-50">
                                        <img src="{{ asset($top['image_path']) }}" alt="" class="h-full w-full object-contain">
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate font-bold text-slate-800">{{ $top['name'] }}</div>
                                    <div class="text-xs text-slate-500">
                                        Lv.{{ $top['level'] }} / {{ $top['job'] }}
                                    </div>
                                </div>
                            </div>
                            <div class="w-20 shrink-0 whitespace-nowrap text-right text-xs font-black text-amber-700 sm:w-24 sm:text-base">
                                戦力 {{ isset($top['power']) ? number_format((int) $top['power']) : '？？？' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- 右カラム：対戦ログ -->
        <div>
            <div class="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden h-full">
                <div class="bg-slate-50 px-4 py-3 border-b border-slate-200">
                    <h3 class="font-bold text-slate-700 flex items-center justify-between gap-3">
                        <span><span class="mr-2">📜</span> 順位変動ログ</span>
                        <span class="text-xs font-bold text-slate-400">自分の関連ログ 最新10件</span>
                    </h3>
                </div>
                <div class="p-0 divide-y divide-slate-100">
                    @forelse($recentLogs as $log)
                        <div class="p-3 {{ $log['is_win'] ? 'bg-green-50/30' : 'bg-red-50/30' }}">
                            <div class="flex items-center justify-between mb-1">
                                <div class="text-xs text-slate-400">{{ $log['created_at']->format('m/d H:i') }}</div>
                                <div class="text-xs font-bold px-2 py-0.5 rounded {{ $log['is_attacker'] ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                                    {{ $log['is_attacker'] ? '攻撃' : '防衛' }}
                                </div>
                            </div>
                            <div class="text-sm text-slate-700">
                                <span class="font-bold">{{ $log['opponent_name'] }}</span>
                                {{ $log['is_attacker'] ? 'に挑んで' : 'に挑まれて' }}
                                @if($log['is_win'])
                                    <span class="text-green-600 font-bold">勝利！</span>
                                @else
                                    <span class="text-red-500 font-bold">敗北…</span>
                                @endif
                            </div>
                            <div class="text-xs text-slate-500 mt-1 flex items-center">
                                順位: {{ $log['old_rank'] }}位
                                @if($log['old_rank'] !== $log['new_rank'])
                                    <span class="mx-1">→</span> 
                                    <span class="{{ $log['new_rank'] < $log['old_rank'] ? 'text-green-600 font-bold' : 'text-red-500 font-bold' }}">{{ $log['new_rank'] }}位</span>
                                @else
                                    <span class="mx-1">→</span> {{ $log['new_rank'] }}位 (変動なし)
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-center text-slate-400 text-sm">
                            まだ対戦ログはありません。
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
