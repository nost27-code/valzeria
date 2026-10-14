<x-layouts.facility
    :title="$battleResult['modeLabel'] . '結果'"
    :subtitle="$battleResult['seasonLabel'] . '・' . $battleResult['roomLabel']"
    headerIconImage="images/icon/icon_005.webp"
    bgImage="images/bg-battle.webp"
    pageBackgroundClass="bg-amber-50"
    :battleResultLayout="true"
    :showBattleChatLog="!isset($simulatorPreview)"
    :showExit="false"
>
    <x-battle-turn-vitals-styles />
    @isset($simulatorPreview)
        <style>[data-six-hero-battle-result-page] .battle-replay .br-controls button { min-height: 44px; min-width: 44px; }</style>
    @endisset
    <div class="mx-auto w-full max-w-4xl pb-4 text-slate-800" data-six-hero-battle-result-page @isset($simulatorPreview) data-battle-result-page @endisset>
        <article class="overflow-hidden rounded-xl border-2 border-amber-300 bg-white shadow-xl">
            <div class="space-y-4 px-3 py-5 sm:px-5">
                @isset($simulatorPreview)
                    <p class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm font-bold text-sky-800" data-simulator-source>
                        管理者模擬戦・取得元 {{ $simulatorPreview['environment'] }} / {{ $simulatorPreview['capturedAt'] }}<br>
                        開始時の能力・装備・対人戦技セットでHP/SPを全快にして対戦します。順位・戦績・HP/SP・報酬は保存されません。
                    </p>
                    <x-battle-replay :replay="$simulatorPreview['battleReplay']"
                        :player-image="\App\Support\CharacterIconCatalog::versionedAsset($battleResult['attackerCombatant']['iconPath'])"
                        :enemy-image="\App\Support\CharacterIconCatalog::versionedAsset($battleResult['defenderCombatant']['iconPath'])" />
                @endisset
                <section class="space-y-4" data-six-hero-combatants aria-labelledby="six-hero-combatants-title">
                    <h2 id="six-hero-combatants-title" class="text-center text-sm font-black tracking-widest text-amber-800">対戦者情報</h2>

                    <div class="mx-auto flex max-w-md items-end justify-center gap-3 py-1 sm:gap-7">
                        <img
                            src="{{ \App\Support\CharacterIconCatalog::versionedAsset($battleResult['attackerCombatant']['iconPath']) }}"
                            alt="{{ $battleResult['attackerCombatant']['name'] }}の戦闘アイコン"
                            class="h-24 w-24 -scale-x-100 object-contain sm:h-32 sm:w-32"
                            data-combatant-icon
                        >
                        <span class="self-center text-2xl font-extrabold italic text-red-500 drop-shadow-md sm:text-3xl">VS</span>
                        <img
                            src="{{ \App\Support\CharacterIconCatalog::versionedAsset($battleResult['defenderCombatant']['iconPath']) }}"
                            alt="{{ $battleResult['defenderCombatant']['name'] }}の戦闘アイコン"
                            class="h-24 w-24 object-contain sm:h-32 sm:w-32"
                            data-combatant-icon
                        >
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        @php
                            $resultCombatants = [
                                ['label' => 'あなた', 'fighter' => $battleResult['attackerCombatant']],
                                ['label' => '対戦相手', 'fighter' => $battleResult['defenderCombatant']],
                            ];
                        @endphp
                        @foreach($resultCombatants as $combatant)
                            @php
                                $fighter = $combatant['fighter'];
                            @endphp
                            <section class="min-w-0 overflow-hidden rounded-xl border-2 border-amber-200 bg-white shadow-sm">
                                <div class="border-b border-amber-200 bg-amber-100 px-3 py-2 text-center">
                                    <div class="text-[10px] font-black tracking-widest text-amber-700">{{ $combatant['label'] }}</div>
                                    <div class="mt-0.5 break-words text-base font-black text-slate-900">{{ $fighter['name'] }}</div>
                                    <div class="mt-0.5 text-xs font-bold text-slate-600">
                                        {{ $fighter['jobName'] }}（職業Lv.{{ $fighter['jobLevel'] }}） / Lv.{{ $fighter['level'] }}
                                    </div>
                                </div>

                                <table class="w-full table-fixed text-center text-xs" data-combatant-stats>
                                    <caption class="border-b border-amber-100 bg-amber-50 px-2 py-1 text-[10px] font-black tracking-widest text-amber-800">戦闘開始時能力</caption>
                                    <tbody>
                                        @foreach(array_chunk($fighter['stats'], 2) as $statRow)
                                            <tr class="border-b border-amber-100 last:border-b-0">
                                                @foreach($statRow as $stat)
                                                    <th class="w-1/4 bg-amber-50 px-1 py-1.5 text-[10px] font-bold text-slate-500 sm:text-xs">{{ $stat['label'] }}</th>
                                                    <td class="w-1/4 px-1 py-1.5 font-black text-slate-800">{{ number_format($stat['value']) }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <div class="border-t border-amber-200 bg-amber-50/60 p-2" data-combatant-equipment>
                                    <div class="mb-1 text-center text-[10px] font-black tracking-widest text-amber-800">装備</div>
                                    <div class="space-y-1">
                                        @forelse($fighter['equipment'] as $equipment)
                                            @php
                                                $rankBadgeClass = match ($equipment['rank'] ?? '') {
                                                    'S', 'SS', 'SSS', 'EPIC' => 'border-amber-500 bg-amber-400 text-white',
                                                    'A' => 'border-violet-500 bg-violet-500 text-white',
                                                    'B' => 'border-sky-500 bg-sky-500 text-white',
                                                    'C' => 'border-emerald-500 bg-emerald-500 text-white',
                                                    default => 'border-slate-400 bg-slate-400 text-white',
                                                };
                                            @endphp
                                            <div class="grid grid-cols-[4.5rem_minmax(0,1fr)] overflow-hidden rounded border border-slate-200 bg-white text-xs font-bold leading-tight text-slate-700">
                                                <span class="flex items-center justify-center gap-1 border-r border-slate-200 bg-slate-50 px-1 text-[10px] text-slate-500">
                                                    <span>{{ $equipment['slot'] }}</span>
                                                    @if($equipment['rank'] !== '')
                                                        <span class="inline-flex min-w-5 items-center justify-center rounded border px-1 py-0.5 text-[9px] font-black {{ $rankBadgeClass }}">{{ $equipment['rank'] }}</span>
                                                    @endif
                                                </span>
                                                <span class="flex min-w-0 items-center gap-1.5 px-2 py-1.5">
                                                    @if($equipment['icon'])
                                                        <img src="{{ asset($equipment['icon']) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                                    @endif
                                                    <span class="truncate">{{ $equipment['name'] }}</span>
                                                </span>
                                            </div>
                                        @empty
                                            <p class="rounded border border-dashed border-slate-300 bg-white px-2 py-2 text-center text-xs font-bold text-slate-500">装備なし</p>
                                        @endforelse
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </div>
                </section>

                @isset($simulatorPreview)
                    <details class="rounded-lg border border-slate-200 bg-white p-3">
                        <summary class="min-h-11 cursor-pointer text-sm font-black text-slate-700">全戦闘ログ</summary>
                @endisset
                <x-six-hero-battle-log :logs="$battleLogs" />
                @isset($simulatorPreview)
                    </details>
                    <details class="rounded-lg border border-slate-200 bg-white p-3" data-compact-accordion="result" data-replay-outcome>
                        <summary class="min-h-11 cursor-pointer text-sm font-black text-slate-700">戦闘結果</summary>
                @endisset
                <section class="text-center" data-battle-outcome @isset($simulatorPreview) data-replay-outcome @endisset>
                    <div class="text-3xl font-black {{ $battleResult['attackerWon'] ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ $battleResult['outcomeLabel'] }}
                    </div>
                    <div class="mt-1 text-sm font-bold text-slate-500">{{ $battleResult['turnCount'] }}ターン</div>
                </section>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach([['label' => 'あなた', 'name' => $battleResult['attackerName'], 'hp' => $battleResult['attackerHp']], ['label' => '対戦相手', 'name' => $battleResult['defenderName'], 'hp' => $battleResult['defenderHp']]] as $fighter)
                        <div class="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] font-black tracking-widest text-slate-500">{{ $fighter['label'] }}・戦闘終了時</div>
                            <div class="mt-0.5 truncate text-sm font-black text-slate-900">{{ $fighter['name'] }}</div>
                            <div class="mt-3 flex items-end justify-between gap-2">
                                <span class="text-sm font-black text-slate-700">HP {{ $fighter['hp']['current'] }} / {{ $fighter['hp']['max'] }}</span>
                                <span class="text-base font-black text-emerald-700">{{ $fighter['hp']['percent'] }}%</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $fighter['hp']['percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($battleResult['mode'] === 'official')
                    <section class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-center" data-official-rank-result>
                        @if($battleResult['rankChangeStatus'] === 'changed')
                            <div class="text-xs font-black tracking-widest text-amber-700">順位上昇</div>
                            <div class="mt-2 flex items-center justify-center gap-3 text-2xl font-black">
                                <span class="text-slate-500">{{ $battleResult['attackerOldRank'] }}位</span>
                                <span class="text-amber-600">→</span>
                                <span class="text-amber-800">{{ $battleResult['attackerNewRank'] }}位</span>
                            </div>
                            <div class="mt-2 text-xs font-bold text-slate-600">
                                {{ $battleResult['defenderName'] }}：{{ $battleResult['defenderOldRank'] }}位 → {{ $battleResult['defenderNewRank'] }}位
                            </div>
                        @elseif($battleResult['rankChangeStatus'] === 'unchanged_concurrent')
                            <div class="text-base font-black text-amber-800">順位変更なし</div>
                            <p class="mt-2 text-sm font-bold leading-relaxed text-slate-600">
                                対戦中のランキング変動により順位変更はありませんでした。
                            </p>
                            <div class="mt-2 text-lg font-black text-slate-900">現在 {{ $battleResult['attackerNewRank'] }}位</div>
                        @elseif($battleResult['rankChangeStatus'] === 'unchanged_loss')
                            <div class="text-base font-black text-slate-700">順位変動なし</div>
                            <div class="mt-2 text-lg font-black text-slate-900">現在 {{ $battleResult['attackerNewRank'] }}位</div>
                        @else
                            <div class="text-base font-black text-slate-700">順位結果は反映されませんでした</div>
                            <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">最新のランキングをご確認ください。</p>
                        @endif
                    </section>

                    <section class="rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-center">
                        <div class="text-[10px] font-black tracking-widest text-slate-600">この間の本日の公式戦</div>
                        <div class="mt-1 text-lg font-black text-slate-950">
                            残り {{ $battleResult['officialAttemptsRemaining'] }} / {{ $battleResult['officialAttemptLimit'] }}
                        </div>
                    </section>
                @else
                    <section class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-center text-sm font-black leading-relaxed text-sky-800">
                        {{ isset($simulatorPreview) ? '管理者模擬戦のため、順位・公式戦績・公式戦回数には影響しません。' : '相性確認のため、順位・公式戦績・公式戦回数には影響しません。' }}
                    </section>
                @endif
                @isset($simulatorPreview)
                    </details>
                @endisset
            </div>
        </article>

        <div class="mt-8 flex justify-center" data-six-hero-result-return>
            <x-back-button
                href="{{ isset($simulatorPreview) ? route('admin.battle-simulator') : route('six-heroes.index', ['room' => $battleResult['roomKey']]) }}"
                :label="isset($simulatorPreview) ? 'シミュレーターへ戻る' : '六極殿へ戻る'"
            />
        </div>
    </div>
</x-layouts.facility>
