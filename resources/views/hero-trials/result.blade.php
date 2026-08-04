<x-layouts.facility
    title="英雄試練"
    subtitle="{{ $outcome['trial']['label'] ?? '英雄試練場' }}"
    headerIconImage="{{ 'images/'.($outcome['trial']['symbol_image'] ?? 'jobbadge/jobbadge_070.webp') }}"
    bgImage="images/bg-battle.webp"
    pageBackgroundClass="bg-black"
    :battleResultLayout="true"
    :showExit="false"
>
    @php
        $trial = (array) ($outcome['trial'] ?? []);
        $passed = (bool) ($outcome['passed'] ?? false);
        $phaseResults = collect($outcome['phase_results'] ?? [])->values();
        $firstPhaseResult = (array) ($phaseResults->first() ?? []);
        $firstPhase = (array) ($firstPhaseResult['phase'] ?? []);
        $firstResult = $firstPhaseResult['result'] ?? [];
        $finalPhaseResult = (array) ($phaseResults->last() ?? []);
        $finalPhase = (array) ($finalPhaseResult['phase'] ?? []);
        $finalResult = $finalPhaseResult['result'] ?? [];
        $finalBattleOutcome = (string) data_get($finalResult, 'result', 'defeat');
        $finalVictory = $finalBattleOutcome === 'victory';
        $playerHpBefore = (int) data_get($firstResult, 'playerHpBefore', data_get($firstResult, 'playerHpAfter', 0));
        $playerMpBefore = (int) data_get($firstResult, 'playerMpBefore', data_get($firstResult, 'playerMpAfter', 0));
        $playerHpAfter = (int) data_get($finalResult, 'playerHpAfter', 0);
        $playerMpAfter = (int) data_get($finalResult, 'playerMpAfter', 0);
        $resultImagePath = $finalVictory ? $characterVictoryImagePath : $characterDefeatImagePath;
        $isMultiPhase = $phaseResults->count() > 1;
        $battleHeading = (string) ($trial['battle_heading'] ?? ($isMultiPhase ? '連続試練戦' : '英雄試練戦'));
        $resultTitle = match (true) {
            $passed => (string) ($trial['victory_title'] ?? (($finalPhase['name'] ?? '試練主').'を打ち破った！')),
            $finalBattleOutcome === 'timeout' => ($finalPhase['label'] ?? '試練') . 'は決着がつかなかった',
            default => ($finalPhase['label'] ?? '試練') . 'で敗退した',
        };
        $speciesNameFor = static function (array $phase): string {
            $speciesKeys = array_values((array) ($phase['species_keys'] ?? []));
            if ($speciesKeys === [] && ! empty($phase['species_key'])) {
                $speciesKeys[] = (string) $phase['species_key'];
            }

            $name = collect($speciesKeys)
                ->map(fn (string $speciesKey): string => (string) (config("enemy_species.labels.{$speciesKey}") ?? '種族不明'))
                ->implode(' / ');

            return $name !== '' ? $name : '種族不明';
        };
        $firstEnemyImagePath = $firstPhase['image_path']
            ?? config('enemy_images')[(string) ($firstPhase['name'] ?? '')]
            ?? null;
    @endphp

    <div class="mx-auto w-full max-w-5xl space-y-5 px-3 py-4 sm:px-6">
        @if($phaseResults->isNotEmpty())
            <section class="overflow-hidden rounded-xl border-2 border-red-200 bg-white shadow-sm">
                <div class="border-b border-red-200 bg-red-50 px-4 py-3">
                    <div class="text-xs font-black text-red-700">{{ $battleHeading }}</div>
                    <div class="mt-1 text-xs font-black text-rose-600">{{ $firstPhase['label'] ?? '試練戦' }}</div>
                    <h2 class="text-lg font-black text-slate-950">{{ $firstPhase['name'] ?? '試練主' }}</h2>
                </div>

                <div class="bg-slate-50 px-3 py-5 sm:px-5">
                    <div class="mb-4 text-center text-sm font-black tracking-[0.25em] text-red-600">戦闘開始</div>
                    <div class="mx-auto grid max-w-2xl grid-cols-[1fr_auto_1fr] items-end gap-3 sm:gap-6">
                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($characterBattleImagePath) }}" alt="{{ $character->name }}" class="h-24 w-24 justify-self-center -scale-x-100 object-contain sm:h-28 sm:w-28">
                        <span class="self-center text-3xl font-extrabold italic text-red-500 drop-shadow-md">VS</span>
                        @if($firstEnemyImagePath)
                            <img src="{{ asset($firstEnemyImagePath) }}" alt="{{ $firstPhase['name'] ?? '試練主' }}" class="h-32 w-32 justify-self-center object-contain drop-shadow-md sm:h-40 sm:w-40">
                        @endif
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="overflow-hidden rounded-lg border-2 border-amber-200 bg-white">
                            <div class="border-b border-amber-200 bg-amber-100 py-1 text-center font-bold text-amber-900">{{ $character->name }}</div>
                            <table class="w-full text-center text-xs sm:text-sm">
                                <tbody>
                                    <tr class="border-b border-amber-100">
                                        <th class="bg-amber-50 py-1 text-slate-600">職業</th>
                                        <td>{{ $character->jobClass->name ?? '冒険者' }} <span class="text-xs">(Lv.{{ $jobLevel }})</span></td>
                                        <th class="bg-amber-50 text-slate-600">Lv</th>
                                        <td class="font-bold">{{ $character->level }}</td>
                                    </tr>
                                    <tr class="border-b border-amber-100">
                                        <th class="bg-amber-50 py-1 text-slate-600">開始HP</th>
                                        <td class="font-bold">{{ number_format($playerHpBefore) }} / {{ number_format((int) $finalStats['max_hp']) }}</td>
                                        <th class="bg-amber-50 text-slate-600">開始SP</th>
                                        <td class="font-bold text-blue-700">{{ number_format($playerMpBefore) }} / {{ number_format((int) $finalStats['max_mp']) }}</td>
                                    </tr>
                                    <tr class="border-b border-amber-100">
                                        <th class="bg-amber-50 py-1 text-slate-600">攻撃</th>
                                        <td>{{ number_format((int) $finalStats['str']) }}</td>
                                        <th class="bg-amber-50 text-slate-600">防御</th>
                                        <td>{{ number_format((int) $finalStats['def']) }}</td>
                                    </tr>
                                    <tr class="border-b border-amber-100">
                                        <th class="bg-amber-50 py-1 text-slate-600">魔力</th>
                                        <td>{{ number_format((int) $finalStats['mag']) }}</td>
                                        <th class="bg-amber-50 text-slate-600">精神</th>
                                        <td>{{ number_format((int) $finalStats['spr']) }}</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-amber-50 py-1 text-slate-600">敏捷</th>
                                        <td>{{ number_format((int) $finalStats['agi']) }}</td>
                                        <th class="bg-amber-50 text-slate-600">運</th>
                                        <td>{{ number_format((int) $finalStats['luk']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="overflow-hidden rounded-lg border-2 border-[#7a2636] bg-[#2a080f] shadow-[0_0_18px_rgba(122,38,54,0.35)]">
                            <div class="border-b border-[#7a2636] bg-[#5a1320] py-1 text-center font-bold text-rose-50">{{ $firstPhase['name'] ?? '試練主' }}</div>
                            <table class="w-full text-center text-xs text-rose-50 sm:text-sm">
                                <tbody>
                                    <tr class="border-b border-[#5a1320]">
                                        <th class="bg-[#4a101a] py-1 text-rose-100">形態</th>
                                        <td>{{ $firstPhase['type_name'] ?? '標準型' }}</td>
                                        <th class="bg-[#4a101a] text-rose-100">HP</th>
                                        <td class="font-bold">{{ number_format((int) ($firstPhase['max_hp'] ?? 0)) }}</td>
                                    </tr>
                                    <tr class="border-b border-[#5a1320]">
                                        <th class="bg-[#4a101a] py-1 text-rose-100">種族</th>
                                        <td colspan="3" class="font-bold">{{ $speciesNameFor($firstPhase) }}</td>
                                    </tr>
                                    <tr class="border-b border-[#5a1320]">
                                        <th class="bg-[#4a101a] py-1 text-rose-100">攻撃</th>
                                        <td>{{ number_format((int) ($firstPhase['str'] ?? 0)) }}</td>
                                        <th class="bg-[#4a101a] text-rose-100">防御</th>
                                        <td>{{ number_format((int) ($firstPhase['def'] ?? 0)) }}</td>
                                    </tr>
                                    <tr class="border-b border-[#5a1320]">
                                        <th class="bg-[#4a101a] py-1 text-rose-100">魔力</th>
                                        <td>{{ number_format((int) ($firstPhase['mag'] ?? 0)) }}</td>
                                        <th class="bg-[#4a101a] text-rose-100">精神</th>
                                        <td>{{ number_format((int) ($firstPhase['spr'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-[#4a101a] py-1 text-rose-100">敏捷</th>
                                        <td>{{ number_format((int) ($firstPhase['agi'] ?? 0)) }}</td>
                                        <th class="bg-[#4a101a] text-rose-100">運</th>
                                        <td>{{ number_format((int) ($firstPhase['luk'] ?? 0)) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                @foreach($phaseResults as $index => $phaseResult)
                    @php
                        $phase = (array) ($phaseResult['phase'] ?? []);
                        $result = $phaseResult['result'] ?? [];
                        $battleLogs = (array) ($phaseResult['display_logs'] ?? data_get($result, 'logs', []));
                        $enemyImagePath = $phase['image_path']
                            ?? config('enemy_images')[(string) ($phase['name'] ?? '')]
                            ?? null;
                        $previousPhase = $index > 0 ? (array) data_get($phaseResults->get($index - 1), 'phase', []) : [];
                    @endphp

                    @if($index > 0)
                        <div class="border-y-2 border-amber-300 bg-gradient-to-b from-slate-950 via-[#340b14] to-slate-950 px-4 py-6 text-center text-white">
                            <p class="text-lg font-black text-amber-200 sm:text-xl">{{ $phase['transition_title'] ?? (($previousPhase['name'] ?? '試練主').'を倒した！') }}</p>
                            <p class="mt-1 text-base font-black tracking-wide text-rose-200 sm:text-lg">{{ $phase['transition_body'] ?? 'だが、次なる試練主が姿を現す……！！' }}</p>
                            @if($enemyImagePath)
                                <img src="{{ asset($enemyImagePath) }}" alt="{{ $phase['name'] ?? '試練主' }}" class="mx-auto mt-4 h-36 w-36 object-contain drop-shadow-[0_0_18px_rgba(251,191,36,0.5)] sm:h-44 sm:w-44">
                            @endif
                            <div class="mt-3 text-xs font-black tracking-[0.2em] text-rose-300">{{ $phase['label'] ?? '次の形態' }}</div>
                            <h3 class="mt-1 text-xl font-black">{{ $phase['name'] ?? '試練主' }}</h3>

                            <div class="mx-auto mt-4 max-w-xl overflow-hidden rounded-lg border-2 border-[#9d4052] bg-[#2a080f] shadow-[0_0_18px_rgba(122,38,54,0.45)]">
                                <table class="w-full text-center text-xs text-rose-50 sm:text-sm">
                                    <tbody>
                                        <tr class="border-b border-[#5a1320]">
                                            <th class="bg-[#4a101a] py-1 text-rose-100">形態</th>
                                            <td>{{ $phase['type_name'] ?? '標準型' }}</td>
                                            <th class="bg-[#4a101a] text-rose-100">HP</th>
                                            <td class="font-bold">{{ number_format((int) ($phase['max_hp'] ?? 0)) }}</td>
                                        </tr>
                                        <tr class="border-b border-[#5a1320]">
                                            <th class="bg-[#4a101a] py-1 text-rose-100">種族</th>
                                            <td colspan="3" class="font-bold">{{ $speciesNameFor($phase) }}</td>
                                        </tr>
                                        <tr class="border-b border-[#5a1320]">
                                            <th class="bg-[#4a101a] py-1 text-rose-100">攻撃</th>
                                            <td>{{ number_format((int) ($phase['str'] ?? 0)) }}</td>
                                            <th class="bg-[#4a101a] text-rose-100">防御</th>
                                            <td>{{ number_format((int) ($phase['def'] ?? 0)) }}</td>
                                        </tr>
                                        <tr class="border-b border-[#5a1320]">
                                            <th class="bg-[#4a101a] py-1 text-rose-100">魔力</th>
                                            <td>{{ number_format((int) ($phase['mag'] ?? 0)) }}</td>
                                            <th class="bg-[#4a101a] text-rose-100">精神</th>
                                            <td>{{ number_format((int) ($phase['spr'] ?? 0)) }}</td>
                                        </tr>
                                        <tr>
                                            <th class="bg-[#4a101a] py-1 text-rose-100">敏捷</th>
                                            <td>{{ number_format((int) ($phase['agi'] ?? 0)) }}</td>
                                            <th class="bg-[#4a101a] text-rose-100">運</th>
                                            <td>{{ number_format((int) ($phase['luk'] ?? 0)) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="space-y-2 border-t border-slate-200 p-4 text-sm font-bold leading-relaxed text-slate-800">
                        @foreach($battleLogs as $line)
                            <div>{!! $line !!}</div>
                        @endforeach
                    </div>
                @endforeach

                <div class="border-t px-4 py-4 {{ $finalVictory ? 'border-cyan-200 bg-cyan-50' : 'border-rose-200 bg-rose-50' }}">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-lg font-black {{ $finalVictory ? 'text-cyan-900' : 'text-rose-900' }}">{{ $resultTitle }}</div>
                            <div class="mt-1 text-xs font-black text-slate-600">
                                残りHP <span class="text-slate-950">{{ number_format($playerHpAfter) }}</span>
                                <span class="mx-2 text-slate-300">/</span>
                                残りSP <span class="text-blue-700">{{ number_format($playerMpAfter) }}</span>
                            </div>
                        </div>
                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($resultImagePath) }}" alt="" class="h-16 w-16 shrink-0 object-contain">
                    </div>
                </div>
            </section>
        @endif

        @if($passed)
            <section class="rounded-xl border-2 border-amber-300 bg-gradient-to-br from-amber-50 to-yellow-100 p-5 text-center shadow-lg">
                <img src="{{ asset('images/'.($trial['job_badge_image'] ?? 'jobbadge/jobbadge_070.webp')) }}" alt="" class="mx-auto h-24 w-24 object-contain drop-shadow">
                <h2 class="mt-3 text-2xl font-black text-amber-950">「{{ $trial['hero_job_name'] ?? '英雄職' }}」への道が開かれた！</h2>
                <p class="mt-2 text-sm font-bold leading-relaxed text-amber-800">神殿へ向かえば、新たに刻まれた英雄職を選べる。</p>
                <a href="{{ route('jobs.index') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-lg border-2 border-amber-700 bg-amber-600 px-6 py-2 text-sm font-black text-white shadow transition hover:bg-amber-700 active:scale-95">
                    神殿で{{ $trial['hero_job_name'] ?? '英雄職' }}を確認する
                </a>
            </section>
        @else
            <section class="rounded-xl border border-slate-300 bg-white p-5 text-center shadow-sm">
                <h2 class="text-lg font-black text-slate-950">試練への道は閉ざされていない</h2>
                <p class="mt-2 text-sm font-bold text-slate-600">{{ $trial['retry_message'] ?? '宿屋でHP/SPを整えれば、試練場から再挑戦できる。' }}</p>
                <a href="{{ route('home') }}" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-lg border-2 border-slate-800 bg-slate-900 px-6 py-2 text-sm font-black text-white shadow transition hover:bg-slate-800 active:scale-95">
                    探索地へ戻る
                </a>
            </section>
        @endif
    </div>
</x-layouts.facility>
