<x-layouts.facility title="闘技場 ランク戦結果" headerIconImage="images/icon/icon_005.webp" bgImage="images/bg-castle.webp">
    {{-- 画面全体の背景を闘技場風に変更 --}}
    <style>
        body {
            background-color: #fdf5f5 !important;
            background-image: url('{{ asset("images/bg-castle.webp") }}') !important;
            background-size: cover !important;
            background-position: center !important;
            background-attachment: fixed !important;
        }
        /* テキストの可読性を保つための半透明オーバーレイ */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 245, 245, 0.85); /* やや赤みがかった白の半透明 */
            z-index: -1;
        }
    </style>

    <div class="py-6 flex flex-col items-center">
        <div class="w-full mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-md sm:rounded-lg overflow-hidden border border-slate-200">
                <div class="p-6 text-slate-800">
                    @php
                        $isNpcBattle = !empty($isNpcBattle);
                        $defenderName = $isNpcBattle
                            ? (string) ($defender['name'] ?? '放浪冒険者')
                            : (string) ($defender->name ?? '不明');
                        $defenderJob = $isNpcBattle
                            ? (string) ($defender['job'] ?? '放浪冒険者')
                            : (string) ($defender->jobClass->name ?? '冒険者');
                        $defenderLevel = $isNpcBattle
                            ? (string) ($defender['level'] ?? '???')
                            : (string) ($defender->level ?? '???');
                        $defenderHp = $isNpcBattle
                            ? '???'
                            : (string) ($defenderStats['max_hp'] ?? '???');
                        $defenderImage = $isNpcBattle ? ($defender['image_path'] ?? null) : null;
                    @endphp

                    {{-- 闘技場ヘッダー --}}
                    <div class="text-center mb-6 border-b-2 border-amber-100 pb-4">
                        <h2 class="text-2xl font-bold text-amber-900 drop-shadow-sm">闘技場 ランク戦</h2>
                        <p class="text-sm text-amber-600 mt-1 font-bold">{{ $arenaLog->attacker_old_rank }}位 のあなたが {{ $arenaLog->defender_old_rank }}位 に挑みました！</p>
                    </div>

                    {{-- ステータス対比テーブル --}}
                    <div class="flex flex-col md:flex-row items-center justify-center gap-4 mb-8">
                        {{-- 自分（攻撃側）情報 --}}
                        <div class="w-full md:w-5/12 border-2 border-amber-200 rounded-lg overflow-hidden">
                            <div class="bg-amber-100 text-amber-900 font-bold text-center py-1 border-b border-amber-200">
                                あなた: {{ $attacker->name }}
                            </div>
                            <table class="w-full text-sm text-center">
                                <tbody>
                                    <tr class="border-b border-amber-100">
                                        <th class="bg-amber-50 w-1/4 py-1 text-slate-600">職業</th>
                                        <td class="w-1/4">{{ $attacker->jobClass->name ?? '冒険者' }}</td>
                                        <th class="bg-amber-50 w-1/4 text-slate-600">Lv</th>
                                        <td class="w-1/4 font-bold">{{ $attacker->level }}</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-amber-50 py-1 text-slate-600">HP</th>
                                        <td colspan="3" class="font-bold text-slate-800">
                                            {{ $attackerStats['max_hp'] }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                        </div>

                        {{-- VS アイコン --}}
                        <div class="w-full md:w-2/12 flex justify-center items-center self-stretch">
                            <span class="text-3xl font-extrabold text-red-500 italic drop-shadow-md">VS</span>
                        </div>

                        {{-- 相手（防衛側）情報 --}}
                        <div class="w-full md:w-5/12 border-2 border-red-200 rounded-lg overflow-hidden flex flex-col">
                            <div class="bg-red-100 text-red-900 font-bold text-center py-1 border-b border-red-200">
                                相手: {{ $defenderName }}
                            </div>
                            @if($defenderImage)
                                <div class="flex justify-center bg-red-50/50 py-3">
                                    <img src="{{ asset($defenderImage) }}" alt="" class="h-20 w-20 object-contain">
                                </div>
                            @endif
                            <table class="w-full text-sm text-center flex-grow">
                                <tbody>
                                    <tr class="border-b border-red-100">
                                        <th class="bg-red-50 w-1/4 py-1 text-slate-600">職業</th>
                                        <td class="w-1/4">{{ $defenderJob }}</td>
                                        <th class="bg-red-50 w-1/4 text-slate-600">Lv</th>
                                        <td class="w-1/4 font-bold">{{ $defenderLevel }}</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-red-50 py-1 text-slate-600">HP</th>
                                        <td colspan="3" class="font-bold text-slate-800">
                                            {{ $defenderHp }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- 戦闘ログ --}}
                    <div class="px-2 mb-6 font-mono text-sm sm:text-base leading-loose text-slate-700">
                        @foreach($result['logs'] as $log)
                            <div>{!! $log !!}</div>
                        @endforeach
                    </div>

                    {{-- 順位変動の演出 --}}
                    <div class="p-6 rounded-lg max-w-2xl mx-auto mb-6 text-center shadow-sm 
                        {{ $result['result'] === 'victory' ? 'bg-green-50 border-2 border-green-200' : 'bg-red-50 border-2 border-red-200' }}">
                        
                        @if($result['result'] === 'victory')
                            <h3 class="font-bold text-green-700 text-2xl mb-4 flex items-center justify-center gap-2">
                                <img src="{{ asset('images/icon/icon_044.webp') }}" alt="" class="w-6 h-6 object-contain"> 勝利！
                            </h3>
                            <div class="text-lg text-slate-700 font-bold">
                                上位ランカーに勝利したため、順位が上がりました！
                            </div>
                            <div class="mt-4 flex items-center justify-center text-3xl font-black">
                                <span class="text-slate-500">{{ $arenaLog->attacker_old_rank }}位</span>
                                <span class="mx-4 text-slate-400">→</span>
                                <span class="text-green-600 animate-pulse">{{ $arenaLog->attacker_new_rank }}位</span>
                            </div>
                        @else
                            <h3 class="font-bold text-red-600 text-2xl mb-4 flex items-center justify-center gap-2">
                                <img src="{{ asset('images/icon/icon_045.webp') }}" alt="" class="w-6 h-6 object-contain"> 敗北…
                            </h3>
                            <div class="text-lg text-slate-700 font-bold">
                                相手の壁は厚かったようです。順位はそのままです。
                            </div>
                            <div class="mt-4 text-xl font-bold text-slate-500">
                                現在の順位: {{ $arenaLog->attacker_old_rank }}位
                            </div>
                        @endif
                    </div>

                    {{-- アクションボタン --}}
                    <div class="flex flex-col sm:flex-row justify-center items-center gap-4 mt-6">
                        <a href="{{ route('home') }}" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                            <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="w-4 h-4 object-contain"> 闘技場へ戻る
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
