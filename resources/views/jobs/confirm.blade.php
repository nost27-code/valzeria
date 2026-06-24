<x-layouts.app>
    <div class="max-w-3xl mx-auto p-4 flex flex-col gap-4 text-sm min-h-screen">
        <!-- ヘッダー -->
        <div class="bg-slate-800 text-white rounded-lg shadow-sm border border-slate-700 flex-shrink-0">
            <div class="p-3 flex justify-between items-center border-b border-slate-700 bg-slate-900 rounded-t-lg">
                <h1 class="font-bold text-xl tracking-wider text-yellow-400 flex items-center gap-2">
                    <img src="{{ asset('images/icon/icon_032.webp') }}" alt="" class="w-7 h-7 object-contain"> 転職の確認
                </h1>
            </div>
            
            <div class="p-3 bg-slate-800 text-[11px] leading-relaxed text-slate-300 rounded-b-lg">
                「お前の努力は認める。だが、転職すればレベルは1に戻り、現在の能力の多くを失うことになる。引き継げるのは成長のほんの一部だ。それでも進む覚悟はあるか？」
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-3 bg-amber-50 border-b border-amber-100 font-bold text-amber-900 flex justify-between items-center">
                <span>{{ optional($character->jobClass)->name ?? '無職' }} から <strong class="text-amber-700">{{ $job->name }}</strong> へ転職します</span>
            </div>
            
            <div class="p-6 flex flex-col md:flex-row gap-6 justify-center items-center">
                <!-- 転職前 -->
                <div class="w-full md:w-2/5 rounded-xl p-5 bg-white shadow-sm ring-1 ring-slate-100">
                    <h3 class="font-bold text-slate-500 text-center mb-4 flex items-center justify-center gap-2">
                        <span class="w-8 h-px bg-slate-200"></span>現在のステータス<span class="w-8 h-px bg-slate-200"></span>
                    </h3>
                    
                    <div class="flex justify-center gap-4 mb-5">
                        <div class="bg-slate-50 px-4 py-2 rounded-lg text-center min-w-[5rem]">
                            <div class="text-slate-400 text-[9px] font-bold mb-0.5">レベル</div>
                            <div class="font-bold text-slate-800 text-lg leading-none">{{ $preview['before']['level'] }}</div>
                        </div>
                        <div class="bg-slate-50 px-4 py-2 rounded-lg text-center min-w-[5rem]">
                            <div class="text-slate-400 text-[9px] font-bold mb-0.5">転職回数</div>
                            <div class="font-bold text-slate-800 text-lg leading-none">{{ $preview['before']['reincarnation_count'] }}</div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-50/80 px-3 py-2 rounded-md flex items-center justify-between">
                            <span class="text-slate-400 font-bold text-[10px]">HP</span><span class="font-bold text-slate-700">{{ $preview['before']['hp'] }}</span>
                        </div>
                        <div class="bg-slate-50/80 px-3 py-2 rounded-md flex items-center justify-between">
                            <span class="text-slate-400 font-bold text-[10px]">攻撃</span><span class="font-bold text-slate-700">{{ $preview['before']['str'] }}</span>
                        </div>
                        <div class="bg-slate-50/80 px-3 py-2 rounded-md flex items-center justify-between">
                            <span class="text-slate-400 font-bold text-[10px]">防御</span><span class="font-bold text-slate-700">{{ $preview['before']['def'] }}</span>
                        </div>
                        <div class="bg-slate-50/80 px-3 py-2 rounded-md flex items-center justify-between">
                            <span class="text-slate-400 font-bold text-[10px]">敏捷</span><span class="font-bold text-slate-700">{{ $preview['before']['agi'] }}</span>
                        </div>
                        <div class="bg-slate-50/80 px-3 py-2 rounded-md flex items-center justify-between">
                            <span class="text-slate-400 font-bold text-[10px]">魔力</span><span class="font-bold text-slate-700">{{ $preview['before']['mag'] }}</span>
                        </div>
                        <div class="bg-slate-50/80 px-3 py-2 rounded-md flex items-center justify-between">
                            <span class="text-slate-400 font-bold text-[10px]">運</span><span class="font-bold text-slate-700">{{ $preview['before']['luk'] }}</span>
                        </div>
                    </div>
                </div>

                <!-- 矢印 -->
                <div class="text-4xl text-amber-300 font-bold flex justify-center items-center">
                    <style>
                        .responsive-arrow { transform: rotate(90deg); }
                        @media (min-width: 768px) { .responsive-arrow { transform: rotate(0deg); } }
                    </style>
                    <div class="responsive-arrow transition-transform duration-300 drop-shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </div>
                </div>

                <!-- 転職後 -->
                <div class="w-full md:w-2/5 rounded-xl p-5 bg-amber-50/30 shadow-md ring-1 ring-amber-100/50 relative overflow-hidden">
                    <!-- アクセントのグラデーション背景 -->
                    <div class="absolute -right-10 -top-10 w-32 h-32 bg-amber-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10"></div>
                    
                    <h3 class="font-bold text-amber-700 text-center mb-4 flex items-center justify-center gap-2 relative z-10">
                        <span class="w-8 h-px bg-amber-200"></span>転職後（予測）<span class="w-8 h-px bg-amber-200"></span>
                    </h3>
                    
                    <div class="flex justify-center gap-4 mb-5 relative z-10">
                        <div class="bg-white shadow-sm px-4 py-2 rounded-lg text-center min-w-[5rem]">
                            <div class="text-amber-400 text-[9px] font-bold mb-0.5">レベル</div>
                            <div class="font-bold text-red-500 text-lg leading-none">{{ $preview['after']['level'] }}</div>
                        </div>
                        <div class="bg-white shadow-sm px-4 py-2 rounded-lg text-center min-w-[5rem]">
                            <div class="text-amber-400 text-[9px] font-bold mb-0.5">転職回数</div>
                            <div class="font-bold text-amber-700 text-lg leading-none">{{ $preview['after']['reincarnation_count'] }}</div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3 relative z-10">
                        <div class="bg-white px-3 py-2 rounded-md flex items-center justify-between shadow-sm">
                            <span class="text-amber-400 font-bold text-[10px]">HP</span><span class="font-bold text-amber-800">{{ $preview['after']['hp'] }}</span>
                        </div>
                        <div class="bg-white px-3 py-2 rounded-md flex items-center justify-between shadow-sm">
                            <span class="text-amber-400 font-bold text-[10px]">攻撃</span><span class="font-bold text-amber-800">{{ $preview['after']['str'] }}</span>
                        </div>
                        <div class="bg-white px-3 py-2 rounded-md flex items-center justify-between shadow-sm">
                            <span class="text-amber-400 font-bold text-[10px]">防御</span><span class="font-bold text-amber-800">{{ $preview['after']['def'] }}</span>
                        </div>
                        <div class="bg-white px-3 py-2 rounded-md flex items-center justify-between shadow-sm">
                            <span class="text-amber-400 font-bold text-[10px]">敏捷</span><span class="font-bold text-amber-800">{{ $preview['after']['agi'] }}</span>
                        </div>
                        <div class="bg-white px-3 py-2 rounded-md flex items-center justify-between shadow-sm">
                            <span class="text-amber-400 font-bold text-[10px]">魔力</span><span class="font-bold text-amber-800">{{ $preview['after']['mag'] }}</span>
                        </div>
                        <div class="bg-white px-3 py-2 rounded-md flex items-center justify-between shadow-sm">
                            <span class="text-amber-400 font-bold text-[10px]">運</span><span class="font-bold text-amber-800">{{ $preview['after']['luk'] }}</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-4 bg-yellow-50 text-yellow-800 text-[10px] leading-relaxed border-t border-yellow-200">
                <strong>【注意事項】</strong><br>
                ※ 転職するとレベルが1になり、経験値も0にリセットされます。<br>
                ※ ステータスは基本値に戻り、これまでの成長分のうち「HPは10%、その他は15%」のみが加算されて引き継がれます。<br>
                ※ 装備中のアイテムは外れませんが、ステータス低下により強い敵に勝てなくなる場合があります。
            </div>
        </div>

        <div class="flex justify-center gap-4 mt-2">
            <form id="jobChangeForm" action="{{ route('jobs.change', ['job' => $job->id]) }}" method="POST">
                @csrf
                <button type="button" onclick="showModal()" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-3 px-8 rounded shadow-md transition-colors hover:-translate-y-0.5 transform">
                    転職を実行する
                </button>
            </form>
        </div>

        <!-- カスタムモーダル -->
        <div id="confirmModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 9999; display: none; align-items: center; justify-content: center;" class="opacity-0 transition-opacity duration-300">
            <!-- 背景オーバーレイ -->
            <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 9998; background-color: rgba(0,0,0,0.7);" onclick="hideModal()"></div>
            
            <!-- モーダル本体 -->
            <div id="modalContent" style="position: relative; z-index: 9999; width: 90%; max-width: 400px;" class="bg-slate-900 border-2 border-amber-500 rounded-lg shadow-xl p-6 text-white transform scale-95 transition-transform duration-300">
                <!-- 閉じるボタン -->
                <button onclick="hideModal()" class="text-slate-400 hover:text-white" style="position: absolute; top: 12px; right: 12px;">
                    <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
                
                <!-- タイトル -->
                <h3 class="font-bold text-yellow-400 border-b border-slate-700 flex items-center gap-2" style="font-size: 16px; margin-bottom: 12px; padding-bottom: 8px;">
                    システム情報
                </h3>
                
                <!-- メッセージ -->
                <p class="text-slate-200" style="font-size: 13px; margin-bottom: 24px; line-height: 1.6;">
                    本当に転職しますか？<br><br>
                    レベルは<strong class="text-red-400 font-bold">1</strong>になり、能力値はリセット（一部引き継ぎ）されます。<br>この操作は取り消せません。
                </p>
                
                <!-- ボタン -->
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button onclick="hideModal()" type="button" class="bg-slate-800 hover:bg-slate-700 text-white border border-slate-600 rounded" style="padding: 8px 16px; font-size: 12px; font-weight: bold; cursor: pointer;">閉じる</button>
                    <button onclick="submitForm()" type="button" class="bg-amber-600 hover:bg-amber-500 text-white border border-amber-400 rounded" style="padding: 8px 16px; font-size: 12px; font-weight: bold; cursor: pointer;">転職を実行</button>
                </div>
            </div>
        </div>

        <script>
            function showModal() {
                const modal = document.getElementById('confirmModal');
                const modalContent = document.getElementById('modalContent');
                modal.style.display = 'flex';
                // 少し遅延させてアニメーションを適用
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    modalContent.classList.remove('scale-95');
                }, 10);
            }

            function hideModal() {
                const modal = document.getElementById('confirmModal');
                const modalContent = document.getElementById('modalContent');
                modal.classList.add('opacity-0');
                modalContent.classList.add('scale-95');
                // アニメーション完了後に非表示
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }

            function submitForm() {
                document.getElementById('jobChangeForm').submit();
            }
        </script>

        <!-- アクションエリア -->
        <div class="mt-8 mb-4 flex justify-center">
            <x-back-button href="{{ route('jobs.index') }}" label="戻る" icon="◀" />
        </div>
    </div>
</x-layouts.app>
