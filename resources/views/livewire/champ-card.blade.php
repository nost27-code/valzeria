@if(!empty($champSummary))
    @php
        $champ = $champSummary['champ'];
        $valmonImagePath = $champValmon?->master?->imageUrl();
        $valmonName = $champValmon?->nickname ?: ($champValmon?->master?->name ?? null);
        $stats = [
            ['攻撃', $champ->atk, 'images/icon/icon_str.webp'],
            ['防御', $champ->def, 'images/icon/icon_def.webp'],
            ['魔法', $champ->mag, 'images/icon/icon_mag.webp'],
            ['精神', $champ->spr, 'images/icon/icon_spr.webp'],
            ['速さ', $champ->spd, 'images/icon/icon_agi.webp'],
            ['運',   $champ->luk, 'images/icon/icon_luk.webp'],
        ];
        $equipment = [
            ['武', $champ->weapon_name],
            ['防', $champ->armor_name],
            ['飾', $champ->accessory_name],
        ];
        $recentLog = (!empty($champSummary['recent_logs']) && $champSummary['recent_logs']->isNotEmpty())
            ? $champSummary['recent_logs']->first()
            : null;
    @endphp

    <section wire:poll.15s
             x-data="{ expanded: false }"
             class="w-full overflow-hidden rounded-lg border border-[#d4af37] bg-white shadow-[0_4px_14px_rgba(126,96,28,0.12)]">

        <button type="button"
                @click="expanded = !expanded"
                class="flex w-full items-center gap-2 bg-[#0a1628] px-3 py-1.5 text-left active:scale-[0.99]">
            <span class="text-[11px] font-black uppercase tracking-widest text-[#d4af37]">Champion</span>
            <span class="ml-auto text-[11px] font-bold text-amber-300 flex items-center gap-1"><img src="{{ asset('images/icon/icon_043.webp') }}" alt="" class="w-3.5 h-3.5 object-contain"> {{ $champ->defense_count }}連勝中</span>
            <svg class="h-3.5 w-3.5 text-amber-200 transition-transform"
                 :class="expanded ? 'rotate-180' : ''"
                 viewBox="0 0 20 20"
                 fill="currentColor"
                 aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </button>

        <div class="relative overflow-hidden bg-white px-3 py-2">
            <div class="relative flex items-center gap-3">
                <div class="flex w-20 shrink-0 items-end justify-center">
                    <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($champ->icon_path ?? '/images/chara/chara_001.webp') }}"
                         alt="{{ $champ->player_name }}"
                         class="h-20 w-16 object-contain drop-shadow-[0_2px_3px_rgba(15,23,42,0.25)]">
                    @if($valmonImagePath)
                        <img src="{{ $valmonImagePath }}"
                             alt="{{ $valmonName }}"
                             class="-ml-3 h-11 w-11 object-contain drop-shadow-[0_2px_3px_rgba(15,23,42,0.25)]">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="truncate text-base font-black leading-tight text-slate-900">{{ $champ->player_name }}</div>
                    <div class="mt-0.5 truncate text-[11px] font-bold text-slate-500">
                        Lv{{ $champ->level }} / {{ $champ->job_name ?? '冒険者' }} Rank {{ $champ->job_rank }}
                    </div>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] font-bold text-slate-400">
                            <span>HP</span>
                            <span class="tabular-nums">{{ number_format($champ->current_hp) }}/{{ number_format($champ->max_hp) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-rose-500 transition-all" style="width:{{ $champSummary['hp_percent'] }}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative mt-2">
                @if($champSummary['is_self'])
                    <div class="rounded-md bg-amber-100 px-3 py-2 text-center text-xs font-black text-amber-800">
                        あなたがチャンプ
                    </div>
                @elseif(!empty($storageIsFull))
                    <div class="rounded-md bg-amber-100 px-2 py-2 text-center text-[11px] font-black leading-snug text-amber-800">
                        倉庫整理が必要
                    </div>
                @elseif($champSummary['can_challenge'])
                    <form action="{{ route('champ.challenge') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf
                        <button type="submit"
                                x-bind:disabled="submitting"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-[#b45309] px-3 py-2 text-sm font-black text-white shadow-sm border border-[#92400e] active:scale-95 disabled:cursor-wait disabled:opacity-70 transition">
                            <x-loading-spinner x-show="submitting" style="display: none;" />
                            <span x-show="!submitting">チャンプに挑む</span>
                            <span x-show="submitting" style="display: none;">戦闘中...</span>
                        </button>
                    </form>
                @else
                    <div class="rounded-md bg-slate-100 px-3 py-2 text-center text-[11px] font-black text-slate-500">
                        待機中
                    </div>
                @endif
            </div>

            <div class="relative mt-2 rounded-md border border-amber-100 bg-white/75 px-2 py-1.5 shadow-sm backdrop-blur-[1px]">
                @if($recentLog)
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate text-[11px] font-semibold text-slate-600">
                            {{ $recentLog->challenger_player_name }}が{{ $recentLog->champ_player_name }}に{{ number_format($recentLog->damage) }}ダメージ
                            @if($recentLog->is_champ_defeated)
                                <span class="font-black text-amber-700">撃破</span>
                            @endif
                        </span>
                        <time class="shrink-0 text-[10px] font-bold text-slate-400 tabular-nums">{{ $recentLog->created_at?->format('H:i') }}</time>
                    </div>
                @else
                    <div class="text-[11px] font-semibold text-slate-400">直近ログはまだありません</div>
                @endif
            </div>
        </div>

        @if(!empty($storageIsFull) && empty($champSummary['is_self']))
            <div class="border-t border-amber-100 bg-amber-50 px-3 py-2 text-[11px] font-black leading-relaxed text-amber-900 break-words">
                {!! $storageFullMessage !!}
            </div>
        @endif

        <div x-show="expanded"
             class="border-t border-amber-100 bg-gradient-to-r from-amber-50/50 via-white to-white">
            <div class="grid gap-3 px-3 py-3 md:grid-cols-[1fr_1.05fr]">
                <div class="space-y-2">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                        @foreach($stats as [$label, $value, $iconPath])
                            <div class="flex items-center justify-between gap-2 text-[11px] font-bold">
                                <span class="flex items-center gap-1 text-slate-500">
                                    <img src="{{ asset($iconPath) }}" class="h-3.5 w-3.5 object-contain" alt="{{ $label }}">
                                    {{ $label }}
                                </span>
                                <span class="tabular-nums text-slate-900">{{ number_format($value) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <div class="mb-0.5 flex justify-between text-[10px] font-bold text-slate-400">
                            <span>HP</span>
                            <span class="tabular-nums">{{ number_format($champ->current_hp) }}/{{ number_format($champ->max_hp) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-rose-500 transition-all" style="width:{{ $champSummary['hp_percent'] }}%"></div>
                        </div>
                    </div>
                </div>

                <div class="space-y-1">
                    @foreach($equipment as [$type, $name])
                        <div class="flex items-center gap-1.5">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-slate-800 text-[10px] font-black text-white">{{ $type }}</span>
                            <span class="min-w-0 truncate text-xs font-bold leading-tight text-slate-700">{{ $name ?: 'なし' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
