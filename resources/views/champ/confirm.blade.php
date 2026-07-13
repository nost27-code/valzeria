<x-layouts.facility title="チャンプ戦" headerIconImage="images/icon/icon_009.webp" bgImage="images/bg-castle.webp">
    @php
        $champ = $summary['champ'];
        $champFatigue = $summary['champ_fatigue'] ?? ['percent' => 0, 'defense_count' => (int) ($champ->defense_count ?? 0)];
        $champStats = $summary['champ_effective_stats'] ?? [
            'atk' => $champ->atk,
            'def' => $champ->def,
            'spd' => $champ->spd,
            'mag' => $champ->mag,
            'spr' => $champ->spr,
            'luk' => $champ->luk,
        ];
        $hpPercent = $summary['hp_percent'];
        $cooldownUntilText = !empty($summary['cooldown_until'])
            ? $summary['cooldown_until']->copy()->timezone('Asia/Tokyo')->format('H:i')
            : null;
    @endphp

    <div class="max-w-2xl mx-auto space-y-4">
        <section class="rounded-lg border border-amber-300 bg-white shadow-sm overflow-hidden">
            <div class="bg-amber-50 px-4 py-3 border-b border-amber-200">
                <div class="flex items-center gap-3">
                    <div class="w-16 h-16 rounded-lg border border-amber-200 bg-white shadow-sm overflow-hidden flex items-center justify-center shrink-0">
                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($champ->icon_path ?? '/images/chara/chara_001.webp') }}"
                             alt="{{ $champ->player_name }}"
                             class="w-full h-full object-contain">
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs font-extrabold text-amber-700">現在のチャンプ</div>
                        <div class="mt-1 text-2xl font-extrabold text-slate-900 truncate">{{ $champ->player_name }}</div>
                        <div class="mt-1 text-sm font-bold text-slate-600">
                            Lv{{ $champ->level }} / {{ $champ->job_name ?? '冒険者' }} Rank {{ $champ->job_rank }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-4 space-y-4">
                <div>
                    <div class="flex items-center justify-between text-xs font-bold text-slate-600">
                        <span>チャンプHP</span>
                        <span>{{ number_format($champ->current_hp) }} / {{ number_format($champ->max_hp) }}</span>
                    </div>
                    <div class="mt-1 h-3 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-rose-500" style="width: {{ $hpPercent }}%;"></div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center text-xs font-bold">
                    <div class="rounded border border-slate-200 bg-slate-50 p-2">攻撃<br><span class="text-base text-slate-900">{{ $champStats['atk'] }}</span></div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2">防御<br><span class="text-base text-slate-900">{{ $champStats['def'] }}</span></div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2">敏捷<br><span class="text-base text-slate-900">{{ $champStats['spd'] }}</span></div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2">魔力<br><span class="text-base text-slate-900">{{ $champStats['mag'] }}</span></div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2">精神<br><span class="text-base text-slate-900">{{ $champStats['spr'] }}</span></div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2">運<br><span class="text-base text-slate-900">{{ $champStats['luk'] }}</span></div>
                </div>

                @if(($champFatigue['percent'] ?? 0) > 0)
                    <div class="rounded border border-orange-200 bg-orange-50 p-3 text-xs font-bold leading-relaxed text-orange-800">
                        連勝疲労：{{ number_format($champFatigue['defense_count'] ?? 0) }}連勝中のため、チャンプの戦闘能力が{{ $champFatigue['percent'] }}%低下しています。
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs font-bold text-slate-700">
                    <div class="rounded border border-amber-100 bg-amber-50 p-2">
                        <div class="text-amber-700">武器</div>
                        <div class="mt-1 truncate text-slate-900">{{ $champ->weapon_name ?? 'なし' }}</div>
                    </div>
                    <div class="rounded border border-amber-100 bg-amber-50 p-2">
                        <div class="text-amber-700">防具</div>
                        <div class="mt-1 truncate text-slate-900">{{ $champ->armor_name ?? 'なし' }}</div>
                    </div>
                    <div class="rounded border border-amber-100 bg-amber-50 p-2">
                        <div class="text-amber-700">装飾</div>
                        <div class="mt-1 truncate text-slate-900">{{ $champ->accessory_name ?? 'なし' }}</div>
                    </div>
                </div>

                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 space-y-2">
                    <div class="text-xs font-extrabold text-emerald-800 tracking-wide mb-1">チャンプ戦に挑むメリット</div>
                    <div class="grid grid-cols-1 gap-1.5 text-xs font-bold text-slate-700">
                        <div class="flex items-start gap-2 rounded bg-white border border-emerald-100 px-2.5 py-2">
                            <img src="{{ asset('images/icon/icon_042.webp') }}" alt="" class="w-4 h-4 object-contain shrink-0 mt-0.5">
                            <div><span class="text-emerald-700">経験値がおいしい</span> — 通常より多めのEXPと少量の職業EXPを獲得。格上チャンプほどEXPボーナス大！</div>
                        </div>
                        <div class="flex items-start gap-2 rounded bg-white border border-emerald-100 px-2.5 py-2">
                            <img src="{{ asset('images/icon/icon_011.webp') }}" alt="" class="w-4 h-4 object-contain shrink-0 mt-0.5">
                            <div><span class="text-emerald-700">素材ボーナス</span> — 挑戦するたびに装備強化に使える素材を1〜2個獲得できる。</div>
                        </div>
                        <div class="flex items-start gap-2 rounded bg-white border border-emerald-100 px-2.5 py-2">
                            <img src="{{ asset('images/icon/icon_039.webp') }}" alt="" class="w-4 h-4 object-contain shrink-0 mt-0.5">
                            <div><span class="text-emerald-700">HP/SP消費なし</span> — 挑戦しても自分のHP・SPは減らない。何度でも気軽に挑める。</div>
                        </div>
                        <div class="flex items-start gap-2 rounded bg-white border border-emerald-100 px-2.5 py-2">
                            <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="w-4 h-4 object-contain shrink-0 mt-0.5">
                            <div><span class="text-emerald-700">先制チャンス</span> — 50%の確率で挑戦者が先手を取れる。</div>
                        </div>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-1 leading-relaxed">チャンプのHPは全冒険者の挑戦で共有されます。HPを0にした冒険者が新チャンプになります。</div>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            @if($summary['is_self'])
                <div class="text-center font-extrabold text-amber-700">あなたは現在のチャンプです</div>
            @elseif(!$summary['can_challenge'])
                <div class="text-center font-extrabold text-slate-700">
                    {{ $summary['reason'] ?? '今は挑戦できません' }}
                    @if($cooldownUntilText)
                        <div class="mt-1 text-xs text-slate-500">次回挑戦: {{ $cooldownUntilText }}</div>
                    @endif
                </div>
            @else
                <form action="{{ route('champ.challenge') }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full rounded-lg bg-[#b45309] px-4 py-3 text-base font-extrabold text-white shadow border border-[#92400e] active:scale-[0.99]">
                        チャンプに挑戦する
                    </button>
                </form>
            @endif
        </section>
    </div>
</x-layouts.facility>
