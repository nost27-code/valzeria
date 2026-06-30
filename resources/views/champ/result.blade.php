<x-layouts.facility title="チャンプ戦 結果" headerIconImage="images/icon/icon_009.webp" bgImage="images/bg-castle.webp">
    @php
        $nextAvailableAt = $result['next_available_at'] ?? null;
        $nextAvailableText = $nextAvailableAt
            ? \Illuminate\Support\Carbon::parse($nextAvailableAt)->timezone('Asia/Tokyo')->format('H:i')
            : '未定';
        $challenger = $result['challenger_actor'] ?? [
            'name' => 'あなた',
            'icon_path' => '/images/chara/chara_001.webp',
            'level' => null,
            'job_name' => '冒険者',
            'job_rank' => null,
            'current_hp' => 0,
            'max_hp' => 1,
            'current_mp' => 0,
            'max_mp' => 0,
            'weapon_name' => null,
            'armor_name' => null,
            'accessory_name' => null,
        ];
        $champ = $result['champ_actor'] ?? [
            'player_name' => $result['champ_before_name'] ?? 'チャンピオン',
            'icon_path' => '/images/chara/chara_001.webp',
            'level' => null,
            'job_name' => '冒険者',
            'job_rank' => null,
            'current_hp' => $result['champ_hp_before'] ?? 0,
            'max_hp' => $result['champ_max_hp'] ?? 1,
            'current_mp' => 0,
            'max_mp' => 0,
            'weapon_name' => null,
            'armor_name' => null,
            'accessory_name' => null,
        ];
        $champ['current_hp'] = $result['champ_hp_after'] ?? ($champ['current_hp'] ?? 0);
        $challengerName = $challenger['name'] ?? 'あなた';
        $champName = $champ['player_name'] ?? $champ['name'] ?? 'チャンピオン';
        $champFatigue = $result['champ_fatigue'] ?? ['percent' => 0, 'defense_count' => 0];

        $actorHpPercent = fn (array $actor) => min(100, (int) floor((max(0, (int) ($actor['current_hp'] ?? 0)) / max(1, (int) ($actor['max_hp'] ?? 1))) * 100));
        $actorMpPercent = fn (array $actor) => (int) ($actor['max_mp'] ?? 0) > 0
            ? min(100, (int) floor((max(0, (int) ($actor['current_mp'] ?? 0)) / max(1, (int) ($actor['max_mp'] ?? 1))) * 100))
            : 0;
        $actorEquipment = fn (array $actor) => [
            ['label' => '武器', 'value' => $actor['weapon_name'] ?? 'なし'],
            ['label' => '防具', 'value' => $actor['armor_name'] ?? 'なし'],
            ['label' => '装飾', 'value' => $actor['accessory_name'] ?? 'なし'],
        ];

        $affinityNet = $result['affinity_net'] ?? 0.0;
        if (abs($affinityNet) < 0.01) {
            $affinityLabel = '互角';
            $affinityClass = 'bg-slate-100 text-slate-500 border-slate-200';
            $affinityPct   = null;
        } elseif ($affinityNet > 0) {
            $affinityLabel = '戦型有利';
            $affinityClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
            $affinityPct   = '+' . round($affinityNet * 100) . '%';
        } else {
            $affinityLabel = '戦型不利';
            $affinityClass = 'bg-red-50 text-red-600 border-red-200';
            $affinityPct   = round($affinityNet * 100) . '%';
        }
    @endphp

    <div class="max-w-4xl mx-auto space-y-4">
        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-center">
                <div class="flex items-center justify-center gap-2 mb-1">
                    <div class="text-[11px] font-extrabold text-slate-500">チャンプ戦</div>
                    <span class="inline-flex items-center gap-1 rounded border {{ $affinityClass }} px-2 py-0.5 text-[11px] font-black">
                        戦型相性：{{ $affinityLabel }}{{ $affinityPct ? ' ' . $affinityPct : '' }}
                    </span>
                    @if(($champFatigue['percent'] ?? 0) > 0)
                        <span class="inline-flex items-center gap-1 rounded border border-orange-200 bg-orange-50 px-2 py-0.5 text-[11px] font-black text-orange-700">
                            連勝疲労 -{{ $champFatigue['percent'] }}%
                        </span>
                    @endif
                </div>
                <div class="mt-1 flex flex-wrap items-center justify-center gap-2 text-xl font-extrabold text-slate-900">
                    <span class="truncate max-w-[42%]">{{ $challengerName }}</span>
                    <span class="text-rose-500 italic">VS</span>
                    <span class="truncate max-w-[42%]">{{ $champName }}</span>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-3 items-stretch">
                @foreach([
                    ['title' => '挑戦者', 'actor' => $challenger, 'name' => $challengerName, 'tone' => 'indigo'],
                    ['title' => '現在のチャンピオン', 'actor' => $champ, 'name' => $champName, 'tone' => 'amber'],
                ] as $index => $side)
                    @if($index === 1)
                        <div class="flex items-center justify-center px-1">
                            <span class="text-2xl font-extrabold text-rose-500 italic drop-shadow-sm">VS</span>
                        </div>
                    @endif

                    @php
                        $actor = $side['actor'];
                        $isChampSide = $side['tone'] === 'amber';
                        $frameClass = $isChampSide ? 'border-amber-200 bg-amber-50/60' : 'border-amber-200 bg-amber-50/60';
                        $labelClass = $isChampSide ? 'text-amber-700' : 'text-amber-700';
                        $barClass = $isChampSide ? 'bg-rose-500' : 'bg-emerald-500';
                    @endphp
                    <div class="rounded-lg border {{ $frameClass }} p-3">
                        <div class="flex items-center gap-3">
                            <div class="w-16 h-16 rounded-lg border border-white bg-white shadow-sm overflow-hidden flex items-center justify-center shrink-0">
                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($actor['icon_path'] ?? '/images/chara/chara_001.webp') }}"
                                     alt="{{ $side['name'] }}"
                                     class="w-full h-full object-contain">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[11px] font-extrabold {{ $labelClass }}">{{ $side['title'] }}</div>
                                <div class="mt-0.5 truncate text-lg font-extrabold text-slate-900">{{ $side['name'] }}</div>
                                <div class="text-xs font-bold text-slate-600">
                                    @if($actor['level'])
                                        Lv{{ $actor['level'] }} /
                                    @endif
                                    {{ $actor['job_name'] ?? '冒険者' }}
                                    @if($actor['job_rank'])
                                        Rank {{ $actor['job_rank'] }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-2">
                            <div>
                                <div class="flex justify-between text-[11px] font-bold text-slate-600">
                                    <span>HP</span>
                                    <span>{{ number_format($actor['current_hp'] ?? 0) }} / {{ number_format($actor['max_hp'] ?? 0) }}</span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-white overflow-hidden border border-slate-100">
                                    <div class="h-full rounded-full {{ $barClass }}" style="width: {{ $actorHpPercent($actor) }}%;"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-[11px] font-bold text-slate-600">
                                    <span>SP</span>
                                    <span>{{ number_format($actor['current_mp'] ?? 0) }} / {{ number_format($actor['max_mp'] ?? 0) }}</span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-white overflow-hidden border border-slate-100">
                                    <div class="h-full rounded-full bg-sky-500" style="width: {{ $actorMpPercent($actor) }}%;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 rounded bg-white/80 px-3 py-2 ring-1 ring-white">
                            <div class="text-[11px] font-extrabold {{ $labelClass }}">装備</div>
                            <div class="mt-1 space-y-1">
                                @foreach($actorEquipment($actor) as $row)
                                    <div class="flex items-center gap-2 text-xs font-bold text-slate-700">
                                        <span class="w-9 shrink-0 {{ $labelClass }}">{{ $row['label'] }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $row['value'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-extrabold text-slate-800">戦闘ログ</div>
            <div class="mt-2 rounded-lg border border-slate-300 bg-white p-4 font-mono text-sm leading-loose text-slate-800 shadow-inner">
                @foreach($result['battle_log'] as $line)
                    {!! $line !!}
                    @if(!$loop->last)
                        <br>
                    @endif
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
            <div class="text-sm font-extrabold text-emerald-700 flex items-center gap-1"><img src="{{ asset('images/icon/icon_010.webp') }}" alt="" class="w-4 h-4 object-contain"> 獲得報酬</div>
            @php
                $levelProgress = $result['progression']['level'] ?? null;
                $jobProgress = $result['progression']['job'] ?? null;
            @endphp
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm font-bold">
                <div class="rounded border border-emerald-100 bg-emerald-50 p-3">
                    EXP<br><span class="text-lg text-slate-900">+{{ number_format((int) ($result['exp_gained'] ?? 0)) }}</span>
                    @if($levelProgress)
                        <div class="mt-1 text-xs text-slate-600">
                            @if(!empty($levelProgress['is_max']))
                                Lv{{ number_format((int) ($levelProgress['level'] ?? 255)) }} 到達済み
                            @else
                                次のLv{{ number_format((int) ($levelProgress['next_level'] ?? 0)) }}まであと {{ number_format((int) ($levelProgress['remaining'] ?? 0)) }}
                            @endif
                        </div>
                    @endif
                </div>
                <div class="rounded border border-emerald-100 bg-emerald-50 p-3">
                    職業EXP<br><span class="text-lg text-slate-900">+{{ number_format((int) ($result['job_exp_gained'] ?? 0)) }}</span>
                    @if($jobProgress)
                        <div class="mt-1 text-xs text-slate-600">
                            @if(!empty($jobProgress['is_mastered']))
                                {{ $jobProgress['job_name'] ?? '現在の職業' }}はマスター済み
                            @else
                                次のランク{{ number_format((int) ($jobProgress['next_rank'] ?? 0)) }}まであと {{ number_format((int) ($jobProgress['remaining'] ?? 0)) }}
                            @endif
                        </div>
                    @endif
                </div>
                <div class="rounded border border-emerald-100 bg-emerald-50 p-3">
                    <div class="inline-flex max-w-full items-center gap-1.5">
                        @php $materialIcon = $result['material_icon_image'] ?? \App\Models\Material::iconImagePathFor($result['material_code'] ?? null, $result['material_name'] ?? null); @endphp
                        @if($materialIcon)
                            <img src="{{ asset($materialIcon) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                        @endif
                        <span class="truncate">{{ $result['material_name'] }}</span>
                    </div>
                    <br><span class="text-lg text-slate-900">+{{ $result['material_quantity'] }}</span>
                </div>
            </div>
            @if(!empty($result['gap_reward_note']))
                <div class="mt-3 rounded border border-amber-100 bg-amber-50 p-3 text-sm font-bold text-amber-800">
                    {{ $result['gap_reward_note'] }}
                </div>
            @endif
            @if($result['champ_defeated'])
                <div class="mt-3 rounded border border-amber-200 bg-amber-50 p-3 text-sm font-extrabold text-amber-800">
                    {{ $result['champ_after_name'] }}が新しいチャンプになりました。
                </div>
            @endif
            @if(!empty($result['level_result']['level_up_count']))
                <div class="mt-3 rounded border border-blue-100 bg-blue-50 p-3 text-sm font-bold text-blue-800">
                    レベルが {{ $result['level_result']['level_up_count'] }} 上がりました。
                </div>
            @endif
            <div class="mt-3 border-t border-emerald-100 pt-3 text-xs font-bold text-slate-500">
                次回挑戦: {{ $nextAvailableText }}
            </div>
        </section>
    </div>
</x-layouts.facility>
