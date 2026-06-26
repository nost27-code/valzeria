<x-layouts.facility :title="(($result['special_event'] ?? null) === 'depth_gate') ? (($result['depth_gate']['label'] ?? '深層') . 'への入口発見') : ((($result['special_event'] ?? null) === 'depth_retreat') ? '探索を継続' : '戦闘開始！')" :headerIconImage="$battleHeaderIconImage ?? 'images/icon/icon_005.webp'" :pageBackgroundStyle="$battleCityBackgroundStyle ?? null" bgImage="images/bg-battle.webp">
    <div class="py-1 flex flex-col items-center" data-battle-result-page>
        <div class="w-full mx-auto sm:px-6 lg:px-8">
            <div class="bg-white/88 shadow-md sm:rounded-lg overflow-hidden border border-slate-200 backdrop-blur-[2px]">
                <div class="p-6 text-slate-800">
                    @if(session('status'))
                        <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4 shadow-sm font-bold">
                            {{ session('status') }}
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 shadow-sm font-bold">
                            {{ session('error') }}
                        </div>
                    @endif

                    {{-- 戦闘結果エラー時 --}}
                    @if(isset($result['error']))
                        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg relative mb-4 shadow-sm" role="alert">
                            <span class="block sm:inline font-bold">{{ $result['error'] }}</span>
                        </div>
                    @else

                        @php
                            $isDungeonLord = in_array(($result['special_event'] ?? null), ['dungeon_lord', 'dungeon_lord_encounter'], true);
                            $isDungeonLordEncounter = ($result['special_event'] ?? null) === 'dungeon_lord_encounter';
                            $isSecretRealmLord = ($result['special_event'] ?? null) === 'secret_realm_lord';
                            $isTreasure = ($result['special_event'] ?? null) === 'treasure';
                            $isHiddenGate = ($result['special_event'] ?? null) === 'hidden_area_gate';
                            $isSubAreaGate = ($result['special_event'] ?? null) === 'sub_area_gate';
                            $isSubAreaExplore = ($result['special_event'] ?? null) === 'sub_area_explore';
                            $isDepthGate = ($result['special_event'] ?? null) === 'depth_gate';
                            $isDepthRetreat = ($result['special_event'] ?? null) === 'depth_retreat';
                            $enemyFrameClass = $isSecretRealmLord
                                ? 'border-slate-800 shadow-xl shadow-amber-950/25'
                                : ($isDungeonLord
                                    ? 'border-[#7f1d1d] shadow-lg shadow-red-950/20'
                                    : (($isHiddenGate || $isSubAreaGate || $isDepthGate) ? 'border-amber-300 shadow-lg shadow-amber-900/10' : 'border-red-200'));
                            $enemyHeaderClass = $isSecretRealmLord
                                ? 'bg-slate-950 text-white border-slate-800 text-lg italic tracking-wide'
                                : ($isDungeonLord
                                    ? 'bg-[#7f1d1d] text-white border-[#5f1515]'
                                    : (($isHiddenGate || $isSubAreaGate || $isDepthGate) ? 'bg-amber-100 text-amber-900 border-amber-300' : 'bg-red-100 text-red-900 border-red-200'));
                            $enemyRowBorderClass = $isSecretRealmLord
                                ? 'border-slate-400'
                                : ($isDungeonLord
                                    ? 'border-[#f3c8c8]'
                                    : (($isHiddenGate || $isSubAreaGate || $isDepthGate) ? 'border-amber-100' : 'border-red-100'));
                            $enemyThClass = $isSecretRealmLord
                                ? 'bg-slate-950 text-white'
                                : ($isDungeonLord
                                    ? 'bg-[#9f3030] text-white'
                                    : (($isHiddenGate || $isSubAreaGate || $isDepthGate) ? 'bg-amber-50 text-amber-900' : 'bg-red-50 text-slate-600'));
                            $enemyTdClass = $isSecretRealmLord
                                ? 'bg-white text-slate-900 font-bold'
                                : ($isDungeonLord
                                    ? 'bg-[#fff7f7] text-[#3f0d0d] font-bold'
                                    : (($isHiddenGate || $isSubAreaGate || $isDepthGate) ? 'bg-white text-amber-900 font-bold' : ''));
                            $enemyTypeText = $isHiddenGate
                                ? '秘境入口'
                                : ($isSubAreaGate ? '共有サブエリア入口' : ($isSubAreaExplore ? '共有サブエリア' : ($isDepthGate ? '探索深度入口' : ($isSecretRealmLord ? '秘境主' : ($isDungeonLord ? '【ダンジョン主】' : ($isBoss ? '【BOSS】' : '通常モンスター'))))));
                            $enemyFamilyLabels = [
                                'standard' => '通常',
                                'slime' => 'スライム',
                                'beast' => '獣',
                                'goblin' => '小鬼',
                                'soldier' => '人型',
                                'mage' => '魔術師',
                                'spirit' => '精霊',
                                'undead' => 'アンデッド',
                                'giant' => '巨人',
                                'insect' => '虫',
                                'flying' => '飛行',
                                'aquatic' => '水棲',
                                'dragon' => '竜',
                                'demon' => '悪魔',
                                'machine' => '機械',
                            ];
                            $enemyFamilyKey = (string) ($result['enemy']->family_key ?? '');
                            $enemyFamilyText = $enemyFamilyLabels[$enemyFamilyKey] ?? ($enemyFamilyKey !== '' ? $enemyFamilyKey : $enemyTypeText);
                            $enemyStatDisplay = $result['enemy_stat_display'] ?? [];
                            $enemyStr = $enemyStatDisplay['str'] ?? ['base' => (int) $result['enemy']->str, 'bonus' => 0, 'total' => (int) $result['enemy']->str];
                            $enemyDef = $enemyStatDisplay['def'] ?? ['base' => (int) $result['enemy']->def, 'bonus' => 0, 'total' => (int) $result['enemy']->def];
                            $enemyDangerRate = (int) ($enemyStatDisplay['danger_rate'] ?? 0);
                            $equipmentDrops = $result['equipment_drops'] ?? [];
                            $secretRealmImage = $result['secret_realm_image'] ?? 'images/map/unexplored_region01.webp';
                            $secretRealmName = $result['secret_realm_name'] ?? '秘境';
                            $rankOrder = ['G' => 0, 'F' => 1, 'E' => 2, 'D' => 3, 'C' => 4, 'B' => 5, 'A' => 6, 'S' => 7, 'SS' => 8, 'SSS' => 9, 'EPIC' => 10];
                            $isSpecialMaterial = function (array $drop): bool {
                                $name = (string) ($drop['name'] ?? '');
                                $kind = (string) ($drop['kind'] ?? '');

                                return $kind === 'branch_evolution'
                                    || str_contains($kind, 'secret_realm')
                                    || str_contains($name, '導石')
                                    || str_contains($name, '古代片')
                                    || str_contains($name, '秘境晶')
                                    || str_contains($name, '極印');
                            };
                        @endphp

                        {{-- ステータス・イベント表示 --}}
                        @if($isDepthGate)
                            @php
                                $depthGate = $result['depth_gate'] ?? [];
                                $depthKey = $depthGate['key'] ?? 'inner';
                                $depthLabel = $depthGate['label'] ?? '深部';
                                $depthAreaName = $depthGate['area_name'] ?? 'この場所';
                                $depthAccent = match ($depthKey) {
                                    'otherworld' => 'from-fuchsia-950 via-indigo-950 to-slate-950',
                                    'deepest' => 'from-slate-950 via-red-950 to-black',
                                    'deep' => 'from-indigo-950 via-slate-950 to-black',
                                    default => 'from-slate-900 via-stone-900 to-black',
                                };
                            @endphp
                            <div class="-mx-6 -mt-6 mb-8 overflow-hidden bg-slate-950 text-white">
                                <div class="relative min-h-[520px] bg-gradient-to-b {{ $depthAccent }} px-5 py-8 sm:px-8">
                                    <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(circle at 30% 20%, rgba(255,255,255,.18), transparent 28%), radial-gradient(circle at 72% 70%, rgba(212,175,55,.22), transparent 32%);"></div>
                                    <div class="relative mx-auto flex max-w-2xl flex-col items-center text-center">
                                        <div class="mb-5 inline-flex items-center rounded-full border border-amber-300/60 bg-black/35 px-4 py-1 text-[12px] font-black tracking-[0.3em] text-amber-200">
                                            {{ strtoupper($depthKey) }} GATE
                                        </div>
                                        <div class="mb-5 flex h-24 w-24 items-center justify-center rounded-full border-2 border-amber-200/80 bg-white/10 text-5xl shadow-2xl shadow-black/40">
                                            <img src="{{ asset($depthKey === 'otherworld' ? 'images/icon/icon_061.webp' : ($depthKey === 'deepest' ? 'images/icon/icon_062.webp' : 'images/icon/icon_063.webp')) }}" alt="" class="w-12 h-12 object-contain">
                                        </div>
                                        <h2 class="text-2xl font-black tracking-wide text-amber-100 sm:text-3xl">
                                            {{ $depthLabel }}への入口を見つけた
                                        </h2>
                                        <p class="mt-5 max-w-xl text-left text-base font-bold leading-8 text-slate-100 sm:text-lg">
                                            {{ $depthGate['entrance_text'] ?? ($depthAreaName . 'の奥で、見慣れない入口を見つけた。') }}
                                        </p>
                                        <div class="mt-6 w-full rounded-xl border border-amber-200/50 bg-white/10 p-4 text-left shadow-xl shadow-black/25 backdrop-blur">
                                            <p class="text-sm font-bold leading-7 text-slate-100">
                                                この先は「<span class="text-amber-200">{{ $depthAreaName }}・{{ $depthLabel }}</span>」です。
                                            </p>
                                            <p class="mt-2 text-sm font-black leading-7 text-amber-100">
                                                推奨Lv:
                                                {{ number_format((int) ($depthGate['recommended_level_min'] ?? 0)) }}〜{{ number_format((int) ($depthGate['recommended_level_max'] ?? 0)) }}
                                                / 現在Lv: {{ number_format((int) ($depthGate['current_level'] ?? 1)) }}
                                            </p>
                                            <p class="mt-4 rounded-lg border border-red-300/40 bg-red-950/45 px-3 py-2 text-sm font-black leading-7 text-red-100">
                                                {{ $depthGate['risk_text'] ?? 'この先へ進むのは危険です。' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @elseif($isDepthRetreat)
                            <div class="mx-auto mb-8 max-w-xl rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-left shadow-sm">
                                <div class="text-sm font-black leading-7 text-amber-900">
                                    危険な入口から引き返しました。
                                </div>
                                <div class="mt-1 text-xs font-bold leading-6 text-amber-700">
                                    現在の探索度・危険度・暫定戦利品は維持したまま、探索を続けられます。
                                </div>
                            </div>
                        @elseif($isHiddenGate)
                            <style>
                                @keyframes secretRealmFadeIn {
                                    0% { opacity: 0; transform: scale(1.045); filter: blur(10px) saturate(0.75); }
                                    55% { opacity: 1; filter: blur(1px) saturate(1.02); }
                                    100% { opacity: 1; transform: scale(1); filter: blur(0) saturate(1.04); }
                                }

                                @keyframes secretRealmTitleIn {
                                    0% { opacity: 0; transform: translateY(-10px); }
                                    100% { opacity: 1; transform: translateY(0); }
                                }

                                .secret-realm-visual {
                                    animation: secretRealmFadeIn 2.4s ease-out both;
                                }

                                .secret-realm-title {
                                    animation: secretRealmTitleIn 1.1s ease-out 0.55s both;
                                }
                            </style>
                            <div class="-mx-6 -mt-6 mb-8 bg-white">
                                <div class="secret-realm-visual relative mx-auto h-[76vh] min-h-[560px] w-full max-w-3xl overflow-hidden bg-slate-950 sm:h-[78vh] sm:min-h-[640px]">
                                    <img
                                        src="{{ asset($secretRealmImage) }}"
                                        alt="{{ $secretRealmName }}への入口"
                                        class="absolute inset-0 h-full w-full object-cover"
                                    >
                                    <div class="absolute inset-0 bg-gradient-to-b from-white/65 via-transparent to-white/88"></div>
                                    <div class="absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-white via-white/70 to-transparent"></div>
                                    <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-white via-white/80 to-transparent"></div>
                                    <div class="absolute inset-y-0 left-0 w-10 bg-gradient-to-r from-white/90 to-transparent sm:w-16"></div>
                                    <div class="absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-white/90 to-transparent sm:w-16"></div>

                                    <div class="secret-realm-title absolute inset-x-4 top-12 text-center sm:top-14">
                                        <div class="mx-auto inline-flex max-w-full items-center justify-center rounded-full bg-yellow-300/90 px-6 py-2 text-base font-black tracking-wider text-slate-800 shadow-lg shadow-yellow-950/20 ring-1 ring-yellow-100 sm:text-xl">
                                            秘境への入口を発見！
                                        </div>
                                        <div class="mt-3 px-2 text-3xl font-black tracking-widest text-yellow-200 drop-shadow-[0_2px_2px_rgba(0,0,0,0.65)] sm:text-4xl">
                                            {{ $secretRealmName }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @else
                            @if($isSecretRealmLord)
                                <style>
                                    @keyframes srlFadeIn {
                                        0% { opacity: 0; transform: scale(1.06); filter: blur(8px); }
                                        60% { opacity: 1; filter: blur(0); }
                                        100% { opacity: 1; transform: scale(1); }
                                    }
                                    @keyframes srlTextIn {
                                        0% { opacity: 0; transform: translateY(8px) scale(0.96); letter-spacing: 0.45em; }
                                        100% { opacity: 1; transform: translateY(0) scale(1); letter-spacing: 0.25em; }
                                    }
                                    @keyframes srlPulse {
                                        0%, 100% { opacity: 0.6; }
                                        50% { opacity: 1; }
                                    }
                                    .srl-banner { animation: srlFadeIn 1.6s ease-out both; }
                                    .srl-label  { animation: srlTextIn 1.1s ease-out 0.5s both; }
                                    .srl-name   { animation: srlTextIn 1.0s ease-out 0.85s both; }
                                    .srl-sub    { animation: srlPulse 2.4s ease-in-out 1.6s infinite; }
                                </style>
                                <div class="srl-banner -mx-6 -mt-6 mb-6 overflow-hidden"
                                     style="background:linear-gradient(160deg,#0a0a12 0%,#1a0a2e 40%,#0f172a 100%);">
                                    <div class="relative px-5 py-7 text-center"
                                         style="background:radial-gradient(ellipse at 50% 0%,rgba(139,92,246,0.18) 0%,transparent 65%);">
                                        <div class="srl-label mb-2 inline-flex items-center gap-2 rounded-full border border-violet-500/50 bg-violet-950/60 px-4 py-1 text-[11px] font-black tracking-[0.3em] text-violet-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-violet-400" style="animation: srlPulse 2.4s ease-in-out 1.6s infinite;"></span>
                                            SECRET REALM LORD
                                            <span class="h-1.5 w-1.5 rounded-full bg-violet-400" style="animation: srlPulse 2.4s ease-in-out 1.6s infinite;"></span>
                                        </div>
                                        <div class="srl-name mt-2 text-2xl font-black tracking-[0.2em] text-white drop-shadow-[0_2px_8px_rgba(139,92,246,0.6)]">
                                            秘境主が現れた！
                                        </div>
                                        <div class="mt-2 text-xs font-bold text-slate-400" style="animation: srlPulse 2.4s ease-in-out 1.6s infinite;">
                                            この秘境の番人に遭遇した
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <div class="flex flex-col md:flex-row items-center justify-center gap-4 mb-8">
                                {{-- キャラクター情報 --}}
                                <div class="w-full {{ $isTreasure ? 'md:w-7/12' : 'md:w-5/12' }} border-2 border-amber-200 rounded-lg overflow-hidden">
                                    <div class="bg-amber-100 text-amber-900 font-bold text-center py-1 border-b border-amber-200">
                                        {{ $character->name }}
                                    </div>
                                    <table class="w-full text-sm text-center">
                                        <tbody>
                                            <tr class="border-b border-amber-100">
                                                <th class="bg-amber-50 w-1/4 py-1 text-slate-600">職業</th>
                                                <td class="w-1/4">{{ $character->jobClass->name ?? '冒険者' }} <span class="text-xs">(Lv.{{ $jobLevel }})</span></td>
                                                <th class="bg-amber-50 w-1/4 text-slate-600">Lv</th>
                                                <td class="w-1/4 font-bold">{{ $character->level }}</td>
                                            </tr>
                                            <tr class="border-b border-amber-100">
                                                <th class="bg-amber-50 py-1 text-slate-600">残りHP</th>
                                                <td class="font-bold {{ $character->current_hp <= ($finalStats['max_hp'] * 0.2) ? 'text-red-600' : 'text-slate-800' }}">
                                                    {{ $character->current_hp }} / {{ $finalStats['max_hp'] }}
                                                </td>
                                                <th class="bg-amber-50 py-1 text-slate-600">残りSP</th>
                                                <td class="font-bold text-blue-700">
                                                    {{ $character->current_mp }} / {{ $finalStats['max_mp'] }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                @if($isTreasure)
                                    <div class="w-full md:w-5/12 rounded-xl border-2 border-amber-300 bg-amber-50 overflow-hidden shadow-lg shadow-amber-900/10">
                                        <div class="bg-gradient-to-b from-amber-100 to-yellow-50 px-4 py-5 text-center">
                                            <img src="{{ asset('images/icon/shining_treasure_chest.webp') }}" alt="輝く宝箱" class="mx-auto h-24 w-24 object-contain drop-shadow-md">
                                            <div class="mt-2 text-xl font-extrabold text-amber-900">輝く宝箱</div>
                                        </div>
                                    </div>
                                @else
                                    {{-- VS アイコン --}}
                                    <div class="w-full md:w-2/12 flex justify-center items-center self-stretch">
                                        <span class="text-3xl font-extrabold text-red-500 italic drop-shadow-md">VS</span>
                                    </div>

                                    {{-- 敵情報 --}}
                                    <div class="w-full md:w-5/12 border-2 {{ $enemyFrameClass }} rounded-lg overflow-hidden flex flex-col">
                                        <div class="{{ $enemyHeaderClass }} font-bold text-center py-1 border-b">
                                            {{ $result['enemy']->name }}
                                        </div>
                                        <table class="w-full text-sm text-center flex-grow">
                                            <tbody>
                                                <tr class="border-b {{ $enemyRowBorderClass }}">
                                                    <th class="{{ $enemyThClass }} w-1/4 py-1">攻撃</th>
                                                    <td class="{{ $enemyTdClass }} w-1/4">
                                                        {{ number_format($enemyStr['base']) }}
                                                        @if(($enemyStr['bonus'] ?? 0) > 0)
                                                            <span class="text-orange-600 font-extrabold">+{{ number_format($enemyStr['bonus']) }}</span>
                                                        @endif
                                                    </td>
                                                    <th class="{{ $enemyThClass }} w-1/4">防御</th>
                                                    <td class="{{ $enemyTdClass }} w-1/4">
                                                        {{ number_format($enemyDef['base']) }}
                                                        @if(($enemyDef['bonus'] ?? 0) > 0)
                                                            <span class="text-orange-600 font-extrabold">+{{ number_format($enemyDef['bonus']) }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th class="{{ $enemyThClass }} py-1">種族</th>
                                                    <td colspan="3" class="{{ $enemyTdClass ?: 'text-slate-600' }}">
                                                        {{ $enemyFamilyText }}
                                                        @if($enemyDangerRate > 0)
                                                            <span class="ml-1 text-orange-700 font-bold">危険度{{ number_format($enemyDangerRate) }}%</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- 戦闘ログ --}}
                        @if(!$isDepthGate && !$isDepthRetreat && !empty($result['log']))
                            <div class="px-2 mb-6 font-mono text-sm sm:text-base leading-loose text-slate-700">
                                {!! nl2br($result['log']) !!}
                            </div>
                        @endif

                        {{-- 報酬・レベルアップ情報 --}}
                        @if(!$isDepthRetreat && ($result['result'] === 'victory' || $result['result'] === 'win'))
                            <div class="bg-amber-50 p-4 rounded-lg mb-6 border border-amber-200 shadow-sm -mx-3">
                                <h3 class="text-lg font-bold text-amber-800 mb-2 flex items-center gap-1">
                                    <img src="{{ asset($isTreasure ? 'images/icon/icon_012.webp' : 'images/icon/icon_010.webp') }}" alt="" class="w-5 h-5 object-contain"> {{ $isTreasure ? '宝箱の中身' : '獲得報酬' }}
                                </h3>
                                @unless($isTreasure)
                                    <p class="mb-1 text-sm font-bold text-slate-700">EXP: <span class="text-amber-600">+{{ $result['exp_gained'] }}</span></p>
                                    @if(($result['gold_gained'] ?? 0) > 0)
                                        <p class="mb-1 text-sm font-bold text-slate-700">Gold: <span class="text-amber-600">+{{ number_format($result['gold_gained']) }}G</span></p>
                                    @endif
                                    <p class="mb-4 text-sm font-bold text-slate-700">Job EXP: <span class="text-green-600">+{{ $result['job_exp_gained'] ?? 0 }}</span></p>
                                @endunless

                                @if(!empty($result['kiseki_drop']))
                                    <div class="mt-4 rounded-lg border border-sky-300 bg-sky-50 p-3 shadow-sm">
                                        <div class="text-sm font-extrabold text-sky-800">まばゆい光がこぼれ落ちた！</div>
                                        <div class="mt-1 flex items-center justify-between gap-3">
                                            <div class="flex items-center gap-2 text-base font-extrabold text-slate-900">
                                                <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-6 w-6 object-contain drop-shadow-sm">
                                                <span>輝石</span>
                                            </div>
                                            <div class="rounded bg-white px-3 py-1 text-sm font-extrabold text-sky-700 border border-sky-100">
                                                +{{ number_format($result['kiseki_drop']['amount'] ?? 1) }}
                                            </div>
                                        </div>
                                        <div class="mt-1 text-xs font-bold text-slate-500">戦闘で入手した無償輝石です。</div>
                                    </div>
                                @endif

                                @if(!empty($result['material_drop']))
                                    <div class="{{ $isTreasure ? 'mt-3' : 'mt-4 pt-4 border-t border-amber-200' }}">
                                        <h4 class="text-lg font-bold text-green-700 mb-2 flex items-center gap-1"><img src="{{ asset('images/icon/icon_011.webp') }}" alt="" class="w-5 h-5 object-contain"> 素材獲得！</h4>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($result['material_drop'] as $materialDrop)
                                                @php
                                                    $specialMaterial = $isSpecialMaterial($materialDrop);
                                                    $materialClass = $specialMaterial
                                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 shadow-sm'
                                                        : 'border-green-200 bg-white text-slate-800 shadow-sm';
                                                @endphp
                                                <span class="inline-flex items-center rounded border {{ $materialClass }} px-3 py-2 text-sm font-bold">
                                                    @if($specialMaterial)
                                                        <span class="mr-1 rounded bg-white/80 px-1.5 py-0.5 text-[10px] text-emerald-700 border border-emerald-200">分岐</span>
                                                    @endif
                                                    {{ $materialDrop['name'] }}
                                                    <span class="ml-2 rounded bg-green-50 px-1.5 py-0.5 text-xs text-green-700">+1</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($result['valmon_material_find']))
                                    @php
                                        $valmonFind = $result['valmon_material_find'];
                                        $valmonImagePath = $valmonFind['valmon_image_path'] ?? null;
                                    @endphp
                                    <div class="mt-4 rounded-lg border border-teal-200 bg-teal-50 p-3 shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-teal-200 bg-white shadow-inner">
                                                @if($valmonImagePath)
                                                    <img src="{{ $valmonImagePath }}" alt="{{ $valmonFind['valmon_name'] ?? 'ヴァルモン' }}" class="h-full w-full object-contain p-1">
                                                @else
                                                    <img src="{{ asset('images/icon/icon_037.webp') }}" alt="" class="h-full w-full object-contain p-1">
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs font-extrabold tracking-wide text-teal-700">VALMON FIND</div>
                                                <div class="mt-0.5 text-sm font-extrabold text-slate-900">
                                                    {{ $valmonFind['valmon_name'] ?? 'ヴァルモン' }}が素材を見つけた！
                                                </div>
                                                <div class="mt-1 inline-flex max-w-full items-center rounded border border-teal-100 bg-white px-3 py-1 text-sm font-extrabold text-teal-800 shadow-sm">
                                                    <span class="truncate">{{ $valmonFind['material_name'] ?? '素材' }}</span>
                                                    <span class="ml-2 shrink-0 rounded bg-teal-50 px-1.5 py-0.5 text-xs text-teal-700">
                                                        +{{ number_format((int) ($valmonFind['quantity'] ?? 1)) }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($result['valmon_discovery_hint']))
                                    @php
                                        $valmonHint = $result['valmon_discovery_hint'];
                                        $valmonImagePath = $valmonHint['valmon_image_path'] ?? null;
                                    @endphp
                                    <div class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-indigo-200 bg-white shadow-inner">
                                                @if($valmonImagePath)
                                                    <img src="{{ $valmonImagePath }}" alt="{{ $valmonHint['valmon_name'] ?? 'ヴァルモン' }}" class="h-full w-full object-contain p-1">
                                                @else
                                                    <img src="{{ asset('images/icon/icon_037.webp') }}" alt="" class="h-full w-full object-contain p-1">
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs font-extrabold tracking-wide text-indigo-700">VALMON SENSE</div>
                                                <div class="mt-0.5 text-sm font-extrabold text-slate-900">
                                                    {{ $valmonHint['valmon_name'] ?? 'ヴァルモン' }}が何かの気配に気づいた
                                                </div>
                                                <div class="mt-1 text-sm font-bold leading-relaxed text-indigo-900">
                                                    {{ $valmonHint['hint'] ?? 'このあたりに、まだ見つけていない気配があるようです。' }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($result['valmon_recovery']))
                                    @php
                                        $valmonRecovery = $result['valmon_recovery'];
                                        $valmonImagePath = $valmonRecovery['valmon_image_path'] ?? null;
                                    @endphp
                                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-emerald-200 bg-white shadow-inner">
                                                @if($valmonImagePath)
                                                    <img src="{{ $valmonImagePath }}" alt="{{ $valmonRecovery['valmon_name'] ?? 'ヴァルモン' }}" class="h-full w-full object-contain p-1">
                                                @else
                                                    <img src="{{ asset('images/icon/icon_037.webp') }}" alt="" class="h-full w-full object-contain p-1">
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs font-extrabold tracking-wide text-emerald-700">VALMON CARE</div>
                                                <div class="mt-0.5 text-sm font-extrabold text-slate-900">
                                                    {{ $valmonRecovery['valmon_name'] ?? 'ヴァルモン' }}が心配そうに寄り添った
                                                </div>
                                                <div class="mt-1 inline-flex items-center rounded border border-emerald-100 bg-white px-3 py-1 text-sm font-extrabold text-emerald-800 shadow-sm">
                                                    HP +{{ number_format((int) ($valmonRecovery['heal_amount'] ?? 0)) }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($result['monster_mark_drop']))
                                    @php $markDrop = $result['monster_mark_drop']; @endphp
                                    <div class="mt-4 pt-4 border-t border-amber-200">
                                        <h4 class="text-lg font-bold text-fuchsia-700 mb-2 flex items-center gap-1"><img src="{{ asset('images/icon/icon_013.webp') }}" alt="" class="w-5 h-5 object-contain"> 印獲得！</h4>
                                        <div class="rounded border border-fuchsia-200 bg-white p-3 shadow-sm">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <div class="font-extrabold text-slate-800">{{ $markDrop['name'] }}</div>
                                                    <div class="text-xs font-bold text-slate-500">
                                                        所持 {{ number_format($markDrop['total_quantity']) }} 個
                                                        @if($markDrop['next_required'] !== null)
                                                            / 次の解放まで {{ number_format($markDrop['next_required']) }} 個
                                                        @else
                                                            / 最大解放
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="rounded bg-fuchsia-50 px-3 py-2 text-sm font-extrabold text-fuchsia-700 border border-fuchsia-100">
                                                    {{ $markDrop['bonus_stat_label'] }} +{{ number_format($markDrop['total_bonus']) }}
                                                </div>
                                            </div>
                                            @if($markDrop['level_up'])
                                                <div class="mt-3 rounded bg-amber-50 border border-amber-200 px-3 py-2 text-sm font-extrabold text-amber-800">
                                                    印図鑑 段階{{ $markDrop['unlocked_level'] }} 解放！
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if($result['level_up_count'] > 0)
                                    <div class="mt-4 pt-4 border-t border-amber-200">
                                        <h4 class="text-lg font-bold text-pink-600 mb-2 animate-pulse flex items-center gap-1"><img src="{{ asset('images/icon/icon_042.webp') }}" alt="" class="w-5 h-5 object-contain"> レベルアップ！</h4>
                                        @foreach($result['level_up_details'] as $detail)
                                            <div class="mb-2 pl-4 border-l-4 border-pink-400 bg-white p-2 rounded shadow-sm">
                                                <p class="font-bold text-slate-800 text-sm">Lv: {{ $detail['level'] - 1 }} → <span class="text-pink-600 text-lg">{{ $detail['level'] }}</span></p>
                                                <p class="text-[11px] text-slate-600 font-bold mt-1">
                                                    最大HP+{{ $detail['hp_up'] }} / 
                                                    最大SP+{{ $detail['mp_up'] }} /
                                                    攻撃+{{ $detail['str_up'] }} / 
                                                    防御+{{ $detail['def_up'] }} / 
                                                    敏捷+{{ $detail['agi_up'] }} / 
                                                    魔力+{{ $detail['mag_up'] }} / 
                                                    精神+{{ $detail['spr_up'] }} / 
                                                    運+{{ $detail['luk_up'] }}
                                                    @if(($detail['bonus_points'] ?? 0) > 0)
                                                        / BP+{{ $detail['bonus_points'] }}
                                                    @endif
                                                </p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if(isset($result['drop']) && $result['drop'])
                                    <div class="mt-4 pt-4 border-t border-amber-200">
                                        <h4 class="text-lg font-bold text-amber-700 mb-2 flex items-center gap-1"><img src="{{ asset('images/icon/icon_011.webp') }}" alt="" class="w-5 h-5 object-contain"> アイテム獲得！</h4>
                                        @php
                                            $dropRank = strtoupper((string) ($result['drop']['rank'] ?? ''));
                                            $isRareDrop = in_array($result['drop']['rarity'], ['rare', 'epic', 'legend'], true)
                                                || (($rankOrder[$dropRank] ?? -1) >= $rankOrder['A']);
                                        @endphp
                                        <div class="mb-2 pl-4 border-l-4 {{ $isRareDrop ? 'border-amber-500 bg-amber-50 ring-1 ring-amber-100' : 'border-amber-400 bg-white' }} p-3 rounded shadow-sm">
                                            @php
                                                $rarityColor = match($result['drop']['rarity']) {
                                                    'rare' => 'text-blue-600',
                                                    'epic' => 'text-purple-600',
                                                    'legend' => 'text-yellow-600',
                                                    default => 'text-slate-600'
                                                };
                                                $rarityName = ucfirst($result['drop']['rarity']);
                                            @endphp
                                            @if($isRareDrop)
                                                <div class="mb-2 inline-flex rounded bg-amber-600 px-2 py-0.5 text-[11px] font-extrabold text-white">
                                                    レアドロップ
                                                </div>
                                            @endif
                                            @if(!empty($result['drop']['has_affix']))
                                                <div class="mb-2 ml-1 inline-flex rounded bg-indigo-600 px-2 py-0.5 text-[11px] font-extrabold text-white">
                                                    {{ ($result['drop']['affix_quality'] ?? null) === 'excellent' ? '逸品装備' : (($result['drop']['affix_quality'] ?? null) === 'good' ? '良品装備' : '銘付き装備') }}
                                                </div>
                                            @endif
                                            <p class="font-bold text-slate-800 text-base">
                                                @if($result['drop']['rarity'] !== 'normal')
                                                    <span class="{{ $rarityColor }} font-extrabold">[{{ $rarityName }}]</span>
                                                @endif
                                                {{ $result['drop']['item_name'] }} を手に入れた！
                                            </p>
                                            <p class="text-xs text-slate-600 font-bold mt-1">
                                                @if($result['drop']['hp_bonus']) HP+{{ $result['drop']['hp_bonus'] }} @endif
                                                @if($result['drop']['mp_bonus']) SP+{{ $result['drop']['mp_bonus'] }} @endif
                                                @if($result['drop']['str_bonus']) 攻撃+{{ $result['drop']['str_bonus'] }} @endif
                                                @if($result['drop']['def_bonus']) 防御+{{ $result['drop']['def_bonus'] }} @endif
                                                @if($result['drop']['agi_bonus']) 敏捷+{{ $result['drop']['agi_bonus'] }} @endif
                                                @if($result['drop']['mag_bonus']) 魔力+{{ $result['drop']['mag_bonus'] }} @endif
                                                @if($result['drop']['spr_bonus']) 精神+{{ $result['drop']['spr_bonus'] }} @endif
                                                @if($result['drop']['luk_bonus']) 運+{{ $result['drop']['luk_bonus'] }} @endif
                                            </p>
                                            @if(!empty($result['drop']['affix_effect_lines']))
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    @foreach($result['drop']['affix_effect_lines'] as $line)
                                                        <span class="rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700">{{ $line }}</span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @php
                                                $dropCharacterItem = !empty($result['drop']['character_item_id'])
                                                    ? \App\Models\CharacterItem::with('item')->find($result['drop']['character_item_id'])
                                                    : null;
                                                $dropItem = $dropCharacterItem?->item;
                                                $permissionService = app(\App\Services\EquipmentPermissionService::class);
                                                $dropCategoryLabel = $dropItem ? $permissionService->categoryLabel($dropItem) : null;
                                                $dropCanEquipByJob = !$dropItem || $permissionService->canEquip($character, $dropItem);
                                                $dropRestrictionJobs = $dropCanEquipByJob ? [] : $permissionService->representativeJobNames($dropItem);
                                            @endphp

                                            @if($dropCategoryLabel || !$dropCanEquipByJob)
                                                <div class="mt-2 text-xs font-bold">
                                                    @if($dropCategoryLabel)
                                                        <span class="inline-flex rounded bg-slate-100 text-slate-600 border border-slate-200 px-2 py-0.5">カテゴリ：{{ $dropCategoryLabel }}</span>
                                                    @endif
                                                    @if(!$dropCanEquipByJob)
                                                        <span class="inline-flex rounded bg-rose-50 text-rose-600 border border-rose-100 px-2 py-0.5 mt-1">
                                                            現在の職業では装備できません
                                                            @if(!empty($dropRestrictionJobs))
                                                                （例：{{ implode('、', $dropRestrictionJobs) }}）
                                                            @endif
                                                        </span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($result['equipment_drops']) && count($result['equipment_drops']) > 1)
                                    <div class="mt-4 pt-4 border-t border-amber-200">
                                        <h4 class="text-lg font-bold text-amber-700 mb-2 flex items-center gap-1"><img src="{{ asset('images/icon/icon_025.webp') }}" alt="" class="w-5 h-5 object-contain"> 追加装備獲得！</h4>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                            @foreach(array_slice($result['equipment_drops'], 1) as $equipmentDrop)
                                                @php
                                                    $extraRank = strtoupper((string) ($equipmentDrop['rank'] ?? ''));
                                                    $extraRare = in_array((string) ($equipmentDrop['rarity'] ?? 'normal'), ['rare', 'epic', 'legend'], true)
                                                        || (($rankOrder[$extraRank] ?? -1) >= $rankOrder['A']);
                                                @endphp
                                                <div class="rounded border {{ $extraRare ? 'border-amber-300 bg-amber-50 ring-1 ring-amber-100' : 'border-amber-100 bg-white' }} p-3 shadow-sm">
                                                    <div class="text-xs font-bold text-amber-600 mb-1">{{ $equipmentDrop['slot_label'] ?? '装備' }} / {{ $equipmentDrop['rank'] ?? $equipmentDrop['rarity'] }}</div>
                                                    @if($extraRare)
                                                        <div class="mb-1 inline-flex rounded bg-amber-600 px-2 py-0.5 text-[10px] font-extrabold text-white">
                                                            レアドロップ
                                                        </div>
                                                    @endif
                                                    @if(!empty($equipmentDrop['has_affix']))
                                                        <div class="mb-1 ml-1 inline-flex rounded bg-indigo-600 px-2 py-0.5 text-[10px] font-extrabold text-white">
                                                            {{ ($equipmentDrop['affix_quality'] ?? null) === 'excellent' ? '逸品装備' : (($equipmentDrop['affix_quality'] ?? null) === 'good' ? '良品装備' : '銘付き装備') }}
                                                        </div>
                                                    @endif
                                                    <div class="font-bold text-slate-800">{{ $equipmentDrop['item_name'] }}</div>
                                                    <div class="mt-1 flex flex-wrap gap-1 text-[11px] font-bold text-slate-500">
                                                        @if($equipmentDrop['hp_bonus']) <span>HP+{{ $equipmentDrop['hp_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['mp_bonus']) <span>SP+{{ $equipmentDrop['mp_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['str_bonus']) <span>攻撃+{{ $equipmentDrop['str_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['def_bonus']) <span>防御+{{ $equipmentDrop['def_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['agi_bonus']) <span>敏捷+{{ $equipmentDrop['agi_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['mag_bonus']) <span>魔力+{{ $equipmentDrop['mag_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['spr_bonus']) <span>精神+{{ $equipmentDrop['spr_bonus'] }}</span> @endif
                                                        @if($equipmentDrop['luk_bonus']) <span>運+{{ $equipmentDrop['luk_bonus'] }}</span> @endif
                                                    </div>
                                                    @if(!empty($equipmentDrop['affix_effect_lines']))
                                                        <div class="mt-2 flex flex-wrap gap-1">
                                                            @foreach($equipmentDrop['affix_effect_lines'] as $line)
                                                                <span class="rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700">{{ $line }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>

                            @if(!$isBoss)
                                @php
                                    $remainingHp = max(0, (int) $character->current_hp);
                                    $maxHp = max(1, (int) ($finalStats['max_hp'] ?? 1));
                                    $hpPercent = min(100, (int) floor(($remainingHp / $maxHp) * 100));
                                    $remainingMp = max(0, (int) $character->current_mp);
                                    $maxMp = max(1, (int) ($finalStats['max_mp'] ?? 1));
                                    $mpPercent = min(100, (int) floor(($remainingMp / $maxMp) * 100));
                                    $hpBarClass = $hpPercent <= 20
                                        ? 'bg-red-500'
                                        : ($hpPercent <= 50 ? 'bg-amber-500' : 'bg-emerald-500');
                                    $hpTextClass = $hpPercent <= 20
                                        ? 'text-red-700'
                                        : ($hpPercent <= 50 ? 'text-amber-700' : 'text-emerald-700');
                                @endphp
                                @php
                                    $explorationSummary = $result['exploration_summary'] ?? null;
                                    $dangerRate = (int) ($explorationSummary['danger_rate'] ?? 0);
                                    $depth = $explorationSummary['depth'] ?? null;
                                    $currentDepth = $depth['current'] ?? [];
                                    $nextDepth = $depth['next'] ?? null;
                                    $dangerBadgeStyle = $dangerRate >= 100
                                        ? 'background:rgba(239,68,68,0.18);color:#fca5a5;border-color:rgba(239,68,68,0.35)'
                                        : ($dangerRate >= 75
                                        ? 'background:rgba(249,115,22,0.18);color:#fdba74;border-color:rgba(249,115,22,0.35)'
                                        : ($dangerRate >= 50
                                        ? 'background:rgba(245,158,11,0.18);color:#fcd34d;border-color:rgba(245,158,11,0.35)'
                                        : 'background:rgba(16,185,129,0.15);color:#6ee7b7;border-color:rgba(16,185,129,0.3)'));
                                    $hpBarColor = $hpPercent <= 20 ? '#ef4444' : ($hpPercent <= 50 ? '#f59e0b' : '#10b981');
                                    $nextNeeds = [];
                                    if (!empty($nextDepth)) {
                                        if (($nextDepth['remaining_point'] ?? 0) > 0) $nextNeeds[] = '探索度+' . number_format($nextDepth['remaining_point']);
                                        if (($nextDepth['remaining_danger'] ?? 0) > 0) $nextNeeds[] = '危険度+' . number_format($nextDepth['remaining_danger']) . '%';
                                    }
                                @endphp
                                {{-- 探索状況カード --}}
                                <div id="post-battle-hp-card"
                                     style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin:0 -12px 16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.06);">

                                    {{-- ヘッダー --}}
                                    <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 14px 8px;border-bottom:1px solid #f1f5f9;">
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <img src="{{ asset('images/icon/icon_002.webp') }}" alt="" style="width:16px;height:16px;object-fit:contain;">
                                            <span style="font-size:11px;font-weight:800;color:#64748b;letter-spacing:0.07em;text-transform:uppercase;">探索状況</span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            @if($depth)
                                                <span style="font-size:10px;font-weight:700;color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe;padding:2px 8px;border-radius:99px;">
                                                    {{ $currentDepth['label'] ?? '表層' }}
                                                </span>
                                            @endif
                                            @if($explorationSummary)
                                                @php
                                                    $dangerBadgeStyleLight = $dangerRate >= 100
                                                        ? 'background:#fef2f2;color:#dc2626;border-color:#fecaca'
                                                        : ($dangerRate >= 75
                                                        ? 'background:#fff7ed;color:#ea580c;border-color:#fed7aa'
                                                        : ($dangerRate >= 50
                                                        ? 'background:#fffbeb;color:#d97706;border-color:#fde68a'
                                                        : 'background:#f0fdf4;color:#16a34a;border-color:#bbf7d0'));
                                                @endphp
                                                <span style="font-size:10px;font-weight:700;border:1px solid;padding:2px 8px;border-radius:99px;{{ $dangerBadgeStyleLight }}">
                                                    危険 {{ $dangerRate }}%
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- HP / SP バー --}}
                                    <div style="padding:10px 14px 0;">
                                        {{-- HP --}}
                                        <div style="margin-bottom:7px;">
                                            <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:3px;">
                                                <span style="font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:0.05em;">HP</span>
                                                <span id="post-battle-hp-text" style="font-size:10px;font-weight:700;color:{{ $hpPercent <= 20 ? '#dc2626' : ($hpPercent <= 50 ? '#d97706' : '#16a34a') }};font-variant-numeric:tabular-nums;">
                                                    {{ number_format($remainingHp) }} / {{ number_format($maxHp) }}&ensp;<span style="opacity:0.6;">{{ $hpPercent }}%</span>
                                                </span>
                                            </div>
                                            <div style="height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                                                <div id="post-battle-hp-bar" style="height:100%;width:{{ $hpPercent }}%;background:{{ $hpBarColor }};border-radius:99px;transition:width 0.4s;"></div>
                                            </div>
                                            <div id="post-battle-hp-percent" class="sr-only">残り {{ $hpPercent }}%</div>
                                        </div>
                                        {{-- SP --}}
                                        <div style="margin-bottom:10px;">
                                            <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:3px;">
                                                <span style="font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:0.05em;">SP</span>
                                                <span id="post-battle-mp-text" style="font-size:10px;font-weight:700;color:#2563eb;font-variant-numeric:tabular-nums;">
                                                    {{ number_format($remainingMp) }} / {{ number_format($maxMp) }}&ensp;<span style="opacity:0.6;">{{ $mpPercent }}%</span>
                                                </span>
                                            </div>
                                            <div style="height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                                                <div id="post-battle-mp-bar" style="height:100%;width:{{ $mpPercent }}%;background:#3b82f6;border-radius:99px;transition:width 0.4s;"></div>
                                            </div>
                                            <div id="post-battle-mp-percent" class="sr-only">残り {{ $mpPercent }}%</div>
                                        </div>
                                    </div>

                                    {{-- 回復アイテム --}}
                                    @if(!empty($recoveryItems))
                                        <div id="exploration-items-panel" style="padding:0 14px 10px;display:flex;flex-direction:column;gap:5px;">
                                            <div style="font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:1px;">回復アイテム</div>
                                            @foreach($recoveryItems as $recoveryItem)
                                                @php
                                                    $canUse = $recoveryItem['item_id'] && $recoveryItem['available_count'] > 0;
                                                    $targetLabel = $recoveryItem['target'] === 'hp' ? 'HP' : 'SP';
                                                @endphp
                                                <div data-exploration-item-id="{{ $recoveryItem['item_id'] }}"
                                                     style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;">
                                                    <div style="min-width:0;flex:1;">
                                                        <span style="font-size:11px;font-weight:700;color:#1e293b;">{{ $recoveryItem['name'] }}</span>
                                                        <span style="font-size:10px;color:#94a3b8;margin-left:6px;" data-item-count>{{ $targetLabel }}{{ $recoveryItem['percent'] }}% &middot; 残{{ $recoveryItem['available_count'] }}</span>
                                                    </div>
                                                    @if($canUse)
                                                        <form action="{{ route('exploration.items.use', ['item' => $recoveryItem['item_id']]) }}" method="POST" class="exploration-item-form" style="margin-left:8px;flex-shrink:0;">
                                                            @csrf
                                                            <button type="submit" data-use-button
                                                                    style="font-size:11px;font-weight:700;color:#fff;background:#0284c7;border:none;padding:4px 14px;border-radius:6px;cursor:pointer;white-space:nowrap;"
                                                                    onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#0284c7'">
                                                                使う
                                                            </button>
                                                        </form>
                                                    @else
                                                        <span style="font-size:11px;font-weight:700;color:#cbd5e1;margin-left:8px;flex-shrink:0;">なし</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- 探索スタット行 --}}
                                    @if($explorationSummary)
                                        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-top:1px solid #f1f5f9;flex-wrap:wrap;">
                                            <div style="display:flex;align-items:baseline;gap:3px;">
                                                <span style="font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:0.05em;">探索度</span>
                                                <span style="font-size:13px;font-weight:800;color:#1e293b;font-variant-numeric:tabular-nums;">{{ number_format($explorationSummary['exploration_point'] ?? 0) }}</span>
                                            </div>
                                            <span style="color:#e2e8f0;font-size:10px;">|</span>
                                            <div style="display:flex;align-items:baseline;gap:3px;">
                                                <span style="font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:0.05em;">連戦</span>
                                                <span style="font-size:13px;font-weight:800;color:#1e293b;font-variant-numeric:tabular-nums;">{{ number_format($explorationSummary['chain_count'] ?? 0) }}</span>
                                            </div>
                                            @if($depth && ($depth['recommended_level_min'] ?? 0))
                                                <span style="color:#e2e8f0;font-size:10px;">|</span>
                                                <div style="display:flex;align-items:baseline;gap:3px;">
                                                    <span style="font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:0.05em;">推奨Lv</span>
                                                    <span style="font-size:11px;font-weight:700;color:#64748b;">{{ number_format($depth['recommended_level_min'] ?? 0) }}〜{{ number_format($depth['recommended_level_max'] ?? 0) }}</span>
                                                </div>
                                            @endif
                                        </div>

                                        {{-- 次フロア / 次目標 --}}
                                        @if(!empty($nextDepth) || !empty($explorationSummary['next_milestone']))
                                            <div style="padding:7px 14px 10px;border-top:1px solid #f1f5f9;">
                                                @if(!empty($nextDepth))
                                                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:{{ !empty($explorationSummary['next_milestone']) ? '4px' : '0' }};">
                                                        <span style="font-size:9px;font-weight:700;color:#94a3b8;white-space:nowrap;">次フロア</span>
                                                        <span style="font-size:10px;font-weight:700;color:#7c3aed;">{{ $nextDepth['label'] ?? '深部' }}</span>
                                                        @if(!empty($nextNeeds))
                                                            <span style="font-size:9px;color:#94a3b8;">（{{ implode(' / ', $nextNeeds) }}）</span>
                                                        @endif
                                                    </div>
                                                @endif
                                                @if(!empty($explorationSummary['next_milestone']))
                                                    <div style="display:flex;align-items:center;gap:6px;">
                                                        <span style="font-size:9px;font-weight:700;color:#94a3b8;white-space:nowrap;">次の発見</span>
                                                        <span style="font-size:10px;font-weight:700;color:#d97706;">{{ $explorationSummary['next_milestone']['point_label'] ?? ('探索度' . number_format($explorationSummary['next_milestone']['point'])) }}</span>
                                                        <span style="font-size:10px;color:#64748b;">{{ $explorationSummary['next_milestone']['name'] }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    @endif

                                    <div id="exploration-item-message" class="hidden" style="padding:6px 14px 10px;font-size:11px;font-weight:700;color:#64748b;"></div>
                                </div>

                                @php
                                    $chainLootSummary = $result['chain_loot_summary'] ?? null;
                                    $valmonEggFound = $result['valmon_egg_found'] ?? null;
                                    $hasChainLoot = (
                                            $chainLootSummary
                                            && (($chainLootSummary['material_total'] ?? 0) > 0 || ($chainLootSummary['item_total'] ?? 0) > 0)
                                        )
                                        || !empty($valmonEggFound);
                                    $isDungeonLordCleared = ($result['special_event'] ?? null) === 'dungeon_lord'
                                        && in_array($result['result'] ?? null, ['victory', 'win'], true);
                                @endphp
                                @if($isDungeonLordCleared)
                                    <div class="bg-emerald-50 p-3 rounded-lg mb-4 border border-emerald-200 shadow-sm">
                                        <div class="flex items-start gap-2">
                                            <img src="{{ asset('images/icon/icon_047.webp') }}" alt="" class="w-5 h-5 object-contain shrink-0">
                                            <div class="min-w-0">
                                                <h3 class="text-sm font-extrabold text-emerald-800">
                                                    探索は継続中です
                                                </h3>
                                                <p class="mt-1 text-xs font-bold leading-relaxed text-emerald-700">
                                                    ダンジョン主を倒しました。探索度・危険度・「この探索で得た物」は維持されます。
                                                    以後、この探索中にダンジョン主は再出現しません。
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if($hasChainLoot)
                                    <div class="bg-amber-50/60 p-3 rounded-lg mb-6 border border-amber-100 -mx-3">
                                        <div class="flex items-center justify-between gap-3 mb-2">
                                            <h3 class="text-sm font-extrabold text-slate-800 flex items-center gap-1">
                                                <img src="{{ asset('images/icon/icon_025.webp') }}" alt="" class="w-4 h-4 object-contain"> この探索で得た物
                                            </h3>
                                            <div class="text-[11px] font-bold text-amber-700">
                                                素材 {{ number_format($chainLootSummary['material_total'] ?? 0) }} / 装備 {{ number_format($chainLootSummary['item_total'] ?? 0) }}
                                            </div>
                                        </div>

                                        @if(($chainLootSummary['risk_total'] ?? 0) > 0)
                                            <div class="mb-2 rounded border border-red-100 bg-red-50 px-2 py-1 text-[11px] font-bold text-red-700">
                                                敗北すると、素材 {{ number_format($chainLootSummary['risk_material_total'] ?? 0) }} 個 / 装備 {{ number_format($chainLootSummary['risk_item_total'] ?? 0) }} 個を失います。
                                            </div>
                                        @endif

                                        <div class="space-y-2">
                                            @if(!empty($chainLootSummary['materials']))
                                                <div class="flex flex-wrap gap-1.5">
                                                    @foreach($chainLootSummary['materials'] as $material)
                                                        @php
                                                            $isSrMaterial = (bool) ($material['is_sr'] ?? false);
                                                            $isSellTreasure = (bool) ($material['is_sell_treasure'] ?? false);
                                                        @endphp
                                                        <span class="inline-flex items-center rounded border px-2 py-1 text-[11px] font-bold {{ $isSellTreasure ? 'border-yellow-300 bg-yellow-50 text-yellow-900' : 'border-emerald-100 bg-emerald-50 ' . ($isSrMaterial ? 'text-violet-700' : 'text-slate-700') }}">
                                                            {{ $material['name'] }}
                                                            <span class="ml-1 {{ $isSellTreasure ? 'text-yellow-700' : 'text-emerald-700' }}">x{{ number_format($material['quantity']) }}</span>
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if(!empty($chainLootSummary['items']))
                                                <div class="flex flex-wrap gap-1.5">
                                                    @foreach($chainLootSummary['items'] as $item)
                                                        <span class="inline-flex items-center rounded border border-amber-100 bg-amber-50 px-2 py-1 text-[11px] font-bold text-slate-700">
                                                            {{ $item['name'] }}
                                                            @if(!empty($item['rank']))
                                                                <span class="ml-1 text-amber-700">{{ $item['rank'] }}</span>
                                                            @endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if(!empty($valmonEggFound))
                                                <div class="flex flex-wrap gap-1.5">
                                                    <span class="inline-flex items-center rounded border border-[#e11d48]/40 bg-rose-50 px-2 py-1 text-[11px] font-extrabold text-[#9f1239] shadow-sm">
                                                        <img src="{{ asset('images/icon/icon_038.webp') }}" alt="" class="w-4 h-4 object-contain mr-1">
                                                        ヴァルモンの卵
                                                        <span class="ml-1 text-[#e11d48]">x1</span>
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($result['material_hunt_completion']))
                                    @php $materialHunt = $result['material_hunt_completion']; @endphp
                                    <div class="mb-6 -mx-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 shadow-sm">
                                        <div class="flex items-start gap-2">
                                            <div class="text-lg leading-none">✓</div>
                                            <div class="min-w-0">
                                                <h3 class="text-sm font-extrabold text-emerald-800">
                                                    {{ $materialHunt['material_name'] ?? '素材' }}が集まった！
                                                </h3>
                                                <p class="mt-1 text-xs font-bold leading-relaxed text-emerald-700">
                                                    必要数 {{ number_format((int) ($materialHunt['required'] ?? 0)) }} 個に到達しました。
                                                    現在 {{ number_format((int) ($materialHunt['owned'] ?? 0)) }} 個 所持しています。
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endif

                        @endif
                    @endif

                    @php
                        $development = $result['development'] ?? null;
                        $showDevelopmentProgress = !empty($development)
                            && (int) ($development['after'] ?? 0) < (int) ($development['max'] ?? 100);
                        $hasNewDiscoveries = !empty($result['new_discoveries'] ?? []);
                    @endphp
                    @if(!isset($result['error']) && ($showDevelopmentProgress || $hasNewDiscoveries))
                        <div class="mt-4 p-4 bg-sky-50 rounded-lg max-w-md mx-auto mb-6 border border-sky-200">
                            <h3 class="font-bold text-sky-900 text-lg mb-2 flex items-center gap-2">
                                <img src="{{ asset('images/icon/icon_002.webp') }}" alt="" class="w-5 h-5 object-contain"> 探索の進展
                            </h3>
                            @if($showDevelopmentProgress)
                                <p class="text-sm text-sky-800 font-bold mb-2">
                                    {{ $development['area_name'] ?? 'この場所' }}の開拓度:
                                    {{ $development['before'] ?? 0 }}
                                    →
                                    {{ $development['after'] ?? 0 }}
                                    / {{ $development['max'] ?? 100 }}
                                </p>
                            @endif
                            @if($hasNewDiscoveries)
                                <div class="space-y-1.5">
                                    @foreach($result['new_discoveries'] as $discovery)
                                        @php
                                            $discoveredAreaId = (int) (($discovery['type'] ?? null) === 'area' ? ($discovery['id'] ?? 0) : 0);
                                            $discoveryBg = $discoveredAreaId > 0 ? ($discoveryAreaCardBackgrounds[$discoveredAreaId] ?? null) : null;
                                        @endphp
                                        @if($discoveryBg)
                                            <div class="relative overflow-hidden rounded-lg border border-sky-200 bg-slate-900 p-4 shadow-sm" style="background-image:linear-gradient(90deg,rgba(240,249,255,.95),rgba(240,249,255,.78),rgba(240,249,255,.34)),url('{{ asset($discoveryBg) }}');background-size:cover;background-position:center;">
                                                <div class="text-[11px] font-black tracking-[0.12em] text-sky-700">NEW AREA</div>
                                                <div class="mt-1 text-lg font-black leading-7 text-sky-950">
                                                    {{ $discovery['name'] ?? '未知の場所' }}
                                                </div>
                                                <div class="mt-1 text-sm font-bold text-sky-800">
                                                    新しい探索先を発見しました！
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-sm font-bold text-sky-700">
                                                新たに「{{ $discovery['name'] ?? '未知の場所' }}」を発見しました！
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                                @php
                                    $newAreaDiscoveries = collect($result['new_discoveries'] ?? [])
                                        ->filter(fn ($discovery) => ($discovery['type'] ?? null) === 'area' && !empty($discovery['id']))
                                        ->values();
                                    $newCityDiscoveries = collect($result['new_discoveries'] ?? [])
                                        ->filter(fn ($discovery) => ($discovery['type'] ?? null) === 'city' && !empty($discovery['id']))
                                        ->values();
                                @endphp
                                @if($newAreaDiscoveries->isNotEmpty())
                                    <div class="mt-3 space-y-2">
                                        @foreach($newAreaDiscoveries as $areaDiscovery)
                                            <form action="{{ route('battle.discovered_area.travel', ['area' => $areaDiscovery['id']]) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-emerald-800 active:scale-95">
                                                    {{ $areaDiscovery['name'] ?? '発見した場所' }}へ進む
                                                </button>
                                            </form>
                                        @endforeach
                                    </div>
                                @endif
                                @if($newCityDiscoveries->isNotEmpty())
                                    <div class="mt-3 space-y-2">
                                        @foreach($newCityDiscoveries as $cityDiscovery)
                                            <form action="{{ route('city.travel', ['city' => $cityDiscovery['id']]) }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="from_battle_result" value="1">
                                                <button type="submit" class="w-full rounded-lg bg-sky-700 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-sky-800 active:scale-95">
                                                    {{ $cityDiscovery['name'] ?? '発見した街' }}へ向かう
                                                </button>
                                            </form>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif

                    @if(!isset($result['error']) && $isBoss && ($result['result'] === 'victory' || $result['result'] === 'win'))
                        <div class="mt-4 p-4 bg-yellow-50 rounded-lg max-w-md mx-auto mb-6 border border-yellow-200">
                            <h3 class="font-bold text-yellow-800 text-lg mb-2 flex items-center gap-2">
                                <img src="{{ asset('images/icon/icon_044.webp') }}" alt="" class="w-5 h-5 object-contain"> ボス撃破！
                            </h3>
                            <p class="text-sm text-yellow-700 font-bold mb-3">
                                {{ $result['enemy']->name }} を倒しました。
                            </p>
                            
                            @if(isset($result['unlocked_areas']) && count($result['unlocked_areas']) > 0)
                                <div class="space-y-2">
                                    @foreach($result['unlocked_areas'] as $newArea)
                                        <div class="text-sm font-bold text-amber-700">
                                            <img src="{{ asset('images/icon/icon_042.webp') }}" alt="" class="w-4 h-4 object-contain inline-block mr-1"> 新たな領域「{{ $newArea->name }}」が解放されました！
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- アクションボタン --}}
                    <div class="flex flex-col sm:flex-row justify-center items-center gap-4 mt-6 pb-6">

                        @php
                            $battleWaitDepthKey = data_get($result, 'exploration_summary.depth.current.key', 'surface');
                            if (($result['special_event'] ?? null) === 'sub_area_explore') {
                                $battleWaitDepthKey = data_get($result, 'exploration_summary.depth.key', $battleWaitDepthKey);
                            }
                            $battleWaitSeconds = app(\App\Services\CooldownSettingService::class)->explorationBattleSecondsForDepthKey($battleWaitDepthKey);
                        @endphp
                        @if(!isset($result['error']) && !$isBoss && $isDepthGate)
                            @php
                                $depthGate = $result['depth_gate'] ?? [];
                                $depthKey = $depthGate['key'] ?? 'inner';
                                $canRecordDepthGate = in_array($depthKey, ['deepest', 'otherworld'], true);
                            @endphp
                            @if($canRecordDepthGate)
                                <form action="{{ route('battle.depth.record', ['area' => $areaId]) }}" method="POST" class="w-full sm:w-auto">
                                    @csrf
                                    <button type="submit" class="flex w-full flex-col rounded-lg border-2 border-amber-300 bg-amber-50 px-5 py-3 text-left shadow-sm transition hover:bg-amber-100 active:scale-95">
                                        <span class="text-sm font-black text-amber-900">地図に記録して探索を続ける</span>
                                        <span class="mt-1 text-[11px] font-bold leading-5 text-amber-700">入口を記録し、後で挑戦できるようにします。現在の探索は続きます。</span>
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('battle.depth.retreat', ['area' => $areaId]) }}" method="POST" class="w-full sm:w-auto">
                                    @csrf
                                    <button type="submit" class="flex w-full flex-col rounded-lg border-2 border-slate-300 bg-white px-5 py-3 text-left shadow-sm transition hover:bg-slate-50 active:scale-95">
                                        <span class="text-sm font-black text-slate-800">引き返して探索を続ける</span>
                                        <span class="mt-1 text-[11px] font-bold leading-5 text-slate-500">{{ $depthGate['label'] ?? '深層' }}には入らず、現在のエリア探索を続けます。</span>
                                    </button>
                                </form>
                            @endif
                            <form action="{{ route('battle.explore', ['area' => $areaId]) }}" method="POST" class="w-full sm:w-auto">
                                @csrf
                                <input type="hidden" name="continue_chain" value="1">
                                <input type="hidden" name="depth_confirmed" value="{{ $depthKey }}">
                                <button type="submit" class="flex w-full flex-col rounded-lg border-2 border-red-900 bg-red-700 px-5 py-3 text-left text-white shadow-md transition hover:bg-red-800 active:scale-95">
                                    <span class="text-sm font-black">それでも進む</span>
                                    <span class="mt-1 text-[11px] font-bold leading-5 text-red-100">{{ $depthGate['label'] ?? '深層' }}へ進みます。危険度が上昇し、敵が大きく強化されます。</span>
                                </button>
                            </form>
                        @elseif(!isset($result['error']) && !$isBoss && $isDungeonLordEncounter)
                            <form action="{{ route('battle.explore', ['area' => $areaId]) }}" method="POST">
                                @csrf
                                <input type="hidden" name="continue_chain" value="1">
                                <input type="hidden" name="challenge_dungeon_lord" value="1">
                                <button type="submit" class="bg-red-700 hover:bg-red-800 text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                                    <img src="{{ asset('images/icon/icon_043.webp') }}" alt="" class="w-4 h-4 object-contain"> ダンジョン主に挑む
                                </button>
                            </form>
                            <form action="{{ route('battle.explore', ['area' => $areaId]) }}" method="POST" id="explore-form" data-async-explore-form data-ready-text="今は退いて探索を続ける" data-wait-seconds="{{ $battleWaitSeconds }}">
                                @csrf
                                <input type="hidden" name="continue_chain" value="1">
                                <button type="submit" id="explore-btn" @disabled($battleWaitSeconds > 0) class="bg-slate-700 hover:bg-slate-800 disabled:bg-slate-400 disabled:cursor-not-allowed text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                                    <span>↩</span> <span id="explore-btn-text">{{ $battleWaitSeconds > 0 ? 'あと ' . $battleWaitSeconds . ' 秒...' : '今は退いて探索を続ける' }}</span>
                                </button>
                            </form>
                        @elseif(!isset($result['error']) && !$isBoss && ($result['special_event'] ?? null) === 'sub_area_explore' && !empty($result['sub_area_discovery_id']))
                            <form action="{{ route('battle.sub_area.explore', ['discovery' => $result['sub_area_discovery_id']]) }}" method="POST" id="explore-form" data-async-explore-form data-ready-text="もう一度探索する" data-wait-seconds="{{ $battleWaitSeconds }}">
                                @csrf
                                <button type="submit" id="explore-btn" @disabled($battleWaitSeconds > 0) class="bg-indigo-700 hover:bg-indigo-800 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                                    <img src="{{ asset('images/icon/icon_003.webp') }}" alt="" class="w-4 h-4 object-contain"> <span id="explore-btn-text">{{ $battleWaitSeconds > 0 ? 'あと ' . $battleWaitSeconds . ' 秒...' : 'もう一度探索する' }}</span>
                                </button>
                            </form>
                        @elseif(!isset($result['error']) && !$isBoss && ($result['result'] === 'victory' || $result['result'] === 'win'))
                            <form action="{{ route('battle.explore', ['area' => $areaId]) }}" method="POST" id="explore-form" data-async-explore-form data-ready-text="もう一度探索する" data-wait-seconds="{{ $battleWaitSeconds }}">
                                @csrf
                                <input type="hidden" name="continue_chain" value="1">
                                <button type="submit" id="explore-btn" @disabled($battleWaitSeconds > 0) class="bg-amber-600 hover:bg-amber-700 disabled:bg-slate-400 disabled:cursor-not-allowed text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                                    <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="w-4 h-4 object-contain"> <span id="explore-btn-text">{{ $battleWaitSeconds > 0 ? 'あと ' . $battleWaitSeconds . ' 秒...' : 'もう一度探索する' }}</span>
                                </button>
                            </form>
                        @elseif(isset($result['error']) && !$isBoss && str_contains((string) $result['error'], '連続で戦闘'))
                            @php
                                $retryWaitSeconds = max(1, $battleWaitSeconds);
                                if (preg_match('/あと\s*(\d+)\s*秒/u', (string) $result['error'], $retryMatches)) {
                                    $retryWaitSeconds = max(1, min(max(1, $battleWaitSeconds), (int) $retryMatches[1]));
                                }
                            @endphp
                            <form action="{{ route('battle.explore', ['area' => $areaId]) }}" method="POST" id="explore-form" data-async-explore-form data-ready-text="探索を続ける" data-wait-seconds="{{ $retryWaitSeconds }}">
                                @csrf
                                <input type="hidden" name="continue_chain" value="1">
                                <button type="submit" id="explore-btn" disabled class="bg-amber-600 hover:bg-amber-700 disabled:bg-slate-400 disabled:cursor-not-allowed text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                                    <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="w-4 h-4 object-contain"> <span id="explore-btn-text">あと {{ $retryWaitSeconds }} 秒...</span>
                                </button>
                            </form>
                        @elseif(!isset($result['error']) && !in_array($result['result'], ['victory', 'win'], true))
                            <a href="{{ route('home') }}"
                               x-data="{ loading: false }"
                               @click="if (!$event.defaultPrevented && !$event.metaKey && !$event.ctrlKey && !$event.shiftKey && $event.button === 0) loading = true"
                               :class="loading ? 'pointer-events-none opacity-80' : ''"
                               class="bg-slate-800 hover:bg-slate-700 text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center gap-2">
                                <img x-show="!loading" src="{{ asset('images/icon/icon_001.webp') }}" alt="" class="w-4 h-4 object-contain opacity-90">
                                <svg x-show="loading" style="display: none;" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                                </svg>
                                <span>街へ戻る</span>
                            </a>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function() {
            if (window.__valzeriaBattleResultHandlersInstalled) {
                window.__valzeriaInitBattleResultTimers && window.__valzeriaInitBattleResultTimers(document);
                return;
            }
            window.__valzeriaBattleResultHandlersInstalled = true;

            const formatNumber = new Intl.NumberFormat('ja-JP');

            function currentResultPage() {
                return document.querySelector('[data-battle-result-page]');
            }

            function hpBarColor(percent) {
                if (percent <= 20) return { bar: '#ef4444', text: '#dc2626' };
                if (percent <= 50) return { bar: '#f59e0b', text: '#d97706' };
                return { bar: '#10b981', text: '#16a34a' };
            }

            function setExplorationItemMessage(text, isSuccess) {
                const message = document.getElementById('exploration-item-message');
                if (!message) return;
                message.textContent = text;
                message.classList.remove('hidden');
                message.style.color = isSuccess ? '#6ee7b7' : '#fca5a5';
            }

            function updateHp(hp) {
                const hpText = document.getElementById('post-battle-hp-text');
                const hpBar = document.getElementById('post-battle-hp-bar');
                const hpPercentText = document.getElementById('post-battle-hp-percent');
                if (!hp || !hpText || !hpBar || !hpPercentText) return;

                const current = Number(hp.current || 0);
                const max = Math.max(1, Number(hp.max || 1));
                const percent = Math.min(100, Math.floor((current / max) * 100));
                const colors = hpBarColor(percent);

                hpText.innerHTML = `${formatNumber.format(current)} / ${formatNumber.format(max)}&ensp;<span style="opacity:0.7;">${percent}%</span>`;
                hpText.style.color = colors.text;
                hpBar.style.width = `${percent}%`;
                hpBar.style.background = colors.bar;
                hpPercentText.textContent = `残り ${percent}%`;
            }

            function updateMp(mp) {
                const mpText = document.getElementById('post-battle-mp-text');
                const mpBar = document.getElementById('post-battle-mp-bar');
                const mpPercentText = document.getElementById('post-battle-mp-percent');
                if (!mp || !mpText || !mpBar || !mpPercentText) return;

                const current = Number(mp.current || 0);
                const max = Math.max(1, Number(mp.max || 1));
                const percent = Math.min(100, Math.floor((current / max) * 100));

                mpText.innerHTML = `${formatNumber.format(current)} / ${formatNumber.format(max)}&ensp;<span style="opacity:0.7;">${percent}%</span>`;
                mpBar.style.width = `${percent}%`;
                mpPercentText.textContent = `残り ${percent}%`;
            }

            function updateExplorationItems(items) {
                if (!Array.isArray(items)) return;

                items.forEach((item) => {
                    const row = document.querySelector(`[data-exploration-item-id="${item.item_id}"]`);
                    if (!row) return;

                    const targetLabel = item.target === 'hp' ? 'HP' : 'SP';
                    const count = row.querySelector('[data-item-count]');
                    if (count) {
                        count.textContent = `${targetLabel}${item.percent}% · 残${item.available_count}`;
                    }

                    const button = row.querySelector('[data-use-button]');
                    if (button && Number(item.available_count) <= 0) {
                        button.disabled = true;
                        button.textContent = 'なし';
                        button.style.background = 'transparent';
                        button.style.color = '#475569';
                        button.style.cursor = 'not-allowed';
                    }
                });
            }

            async function submitExplorationItem(form) {
                const button = form.querySelector('[data-use-button]');
                if (button) {
                    button.disabled = true;
                    button.textContent = '使用中...';
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                    });
                    const data = await response.json();

                    updateHp(data.hp);
                    updateMp(data.mp);
                    updateExplorationItems(data.items);
                    setExplorationItemMessage(data.message || 'アイテムを使用しました。', data.success === true);
                } catch (error) {
                    setExplorationItemMessage('アイテムの使用に失敗しました。通信状態を確認してください。', false);
                } finally {
                    if (button && !button.classList.contains('cursor-not-allowed')) {
                        button.disabled = false;
                        button.textContent = '使う';
                    }
                }
            }

            async function submitExploreAgain(form) {
                const button = form.querySelector('#explore-btn');
                const buttonText = form.querySelector('#explore-btn-text');

                if (form.dataset.submitted === '1') return;
                form.dataset.submitted = '1';
                if (button) {
                    button.disabled = true;
                    button.classList.add('scale-95', 'opacity-80');
                }
                if (buttonText) {
                    buttonText.textContent = '探索中...';
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                        credentials: 'same-origin',
                    });
                    const html = await response.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const nextPage = doc.querySelector('[data-battle-result-page]');
                    const currentPage = currentResultPage();

                    if (!response.ok || !nextPage || !currentPage) {
                        HTMLFormElement.prototype.submit.call(form);
                        return;
                    }

                    currentPage.replaceWith(document.importNode(nextPage, true));
                    if (response.url) {
                        window.history.replaceState({}, '', response.url);
                    }

                    window.__valzeriaInitBattleResultTimers(document);
                    const replacedPage = currentResultPage();
                    if (replacedPage) {
                        replacedPage.scrollIntoView({ block: 'start' });
                    }
                } catch (error) {
                    HTMLFormElement.prototype.submit.call(form);
                }
            }

            window.__valzeriaInitBattleResultTimers = function(root) {
                const forms = (root || document).querySelectorAll('[data-async-explore-form]');
                forms.forEach((form) => {
                    if (form.dataset.timerBound === '1') return;
                    form.dataset.timerBound = '1';

                    const button = form.querySelector('#explore-btn');
                    const buttonText = form.querySelector('#explore-btn-text');
                    if (!button || !buttonText) return;

                    let timeLeft = Number.parseInt(form.dataset.waitSeconds || '{{ $battleWaitSeconds }}', 10);
                    if (!Number.isFinite(timeLeft) || timeLeft < 0) {
                        timeLeft = {{ $battleWaitSeconds }};
                    }
                    if (timeLeft <= 0) {
                        button.disabled = false;
                        buttonText.textContent = form.dataset.readyText || 'もう一度探索する';
                        return;
                    }
                    buttonText.textContent = 'あと ' + timeLeft + ' 秒...';
                    const timer = setInterval(() => {
                        if (!document.body.contains(form)) {
                            clearInterval(timer);
                            return;
                        }

                        timeLeft--;
                        if (timeLeft <= 0) {
                            clearInterval(timer);
                            button.disabled = false;
                            buttonText.textContent = form.dataset.readyText || 'もう一度探索する';
                        } else {
                            buttonText.textContent = 'あと ' + timeLeft + ' 秒...';
                        }
                    }, 1000);
                });
            };

            document.addEventListener('submit', function(event) {
                const itemForm = event.target.closest('.exploration-item-form');
                if (itemForm) {
                    event.preventDefault();
                    submitExplorationItem(itemForm);
                    return;
                }

                const exploreForm = event.target.closest('[data-async-explore-form]');
                if (exploreForm) {
                    event.preventDefault();
                    submitExploreAgain(exploreForm);
                }
            });

            document.addEventListener('DOMContentLoaded', function() {
                window.__valzeriaInitBattleResultTimers(document);
            });
        })();
    </script>
</x-layouts.facility>
