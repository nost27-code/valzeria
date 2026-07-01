@php
    $permissionService = $permissionService ?? app(\App\Services\EquipmentPermissionService::class);
    $currentCharacter = $currentCharacter ?? $character ?? Auth::user()->currentCharacter();
    $categoryLabel = $permissionService->categoryLabel($ci->item);
    $canEquipByJob = !$currentCharacter || $permissionService->canEquip($currentCharacter, $ci->item);
    $restrictionJobs = $canEquipByJob ? [] : $permissionService->representativeJobNames($ci->item);
    $displayName = $ci->displayName();
    $equipmentIcon = $ci->item?->iconImagePath();
    $sellPrice = (int) ($ci->sell_price ?? 0);
    $affixLines = $ci->affixEffectLines();
    $totalStats = [
        'hp' => (int) ($ci->item->hp_bonus ?? 0) + (int) ($ci->affix_hp_bonus ?? 0),
        'str' => (int) ($ci->item->str_bonus ?? 0) + (int) ($ci->affix_str_bonus ?? 0),
        'def' => (int) ($ci->item->def_bonus ?? 0) + (int) ($ci->affix_def_bonus ?? 0),
        'agi' => (int) ($ci->item->agi_bonus ?? 0) + (int) ($ci->affix_agi_bonus ?? 0),
        'mag' => (int) ($ci->item->mag_bonus ?? 0) + (int) ($ci->affix_mag_bonus ?? 0),
        'spr' => (int) ($ci->item->spr_bonus ?? 0) + (int) ($ci->affix_spr_bonus ?? 0),
        'luk' => (int) ($ci->item->luk_bonus ?? 0) + (int) ($ci->affix_luk_bonus ?? 0),
    ];
@endphp

<div class="equipment-item relative border {{ $ci->is_equipped ? 'border-amber-300 bg-amber-50' : ($canEquipByJob ? 'border-slate-200 bg-white' : 'border-slate-200 bg-slate-50/60') }} rounded-lg transition-all duration-200"
     data-character-item-id="{{ $ci->id }}"
     data-equipment-tab="{{ $ci->item->type }}"
     data-equipped="{{ $ci->is_equipped ? '1' : '0' }}"
     data-can-equip="{{ $canEquipByJob ? '1' : '0' }}"
     data-locked="{{ $ci->is_locked ? '1' : '0' }}"
     data-can-sell="{{ ($sellPrice > 0) ? '1' : '0' }}"
     data-equip-url="{{ route('equipment.equip', $ci) }}"
     data-unequip-url="{{ route('equipment.unequip', $ci) }}"
     data-lock-url="{{ route('equipment.lock', $ci) }}"
     data-sort-recommend="{{ $ci->sort_recommend ?? 0 }}"
     data-sort-str="{{ $ci->sort_str ?? 0 }}"
     data-sort-def="{{ $ci->sort_def ?? 0 }}"
     data-sort-mag="{{ $ci->sort_mag ?? 0 }}"
     data-sort-agi="{{ $ci->sort_agi ?? 0 }}"
     data-sort-rank="{{ $ci->sort_rank ?? 0 }}"
     data-sort-new="{{ $ci->sort_new ?? $ci->id }}">

    {{-- 保護ボタン（右上★） --}}
    <form action="{{ route('equipment.lock', $ci) }}" method="POST"
          class="equipment-action-form equipment-lock-form absolute top-2 right-2 z-10"
          data-equipment-action="lock">
        @csrf
        <button type="submit" data-action-button
            title="{{ $ci->is_locked ? '保護を解除する' : '保護する（売却・破棄防止）' }}"
            class="w-7 h-7 flex items-center justify-center rounded-full transition-all duration-150 active:scale-90
                   {{ $ci->is_locked
                       ? 'text-yellow-500 bg-yellow-50 border border-yellow-300 shadow-sm'
                       : 'text-slate-300 hover:text-yellow-400 bg-transparent' }}">
            <span class="text-base leading-none equipment-lock-star">{{ $ci->is_locked ? '★' : '☆' }}</span>
        </button>
    </form>

    <div class="px-3 py-2.5 pr-9 flex flex-col gap-2">

        {{-- ヘッダー行: ランク + 名前 + バッジ --}}
        <div>
            <div class="flex flex-wrap items-center gap-1.5">
                @if($equipmentIcon)
                    <img src="{{ asset($equipmentIcon) }}" alt="" class="h-6 w-6 shrink-0 object-contain">
                @endif
                @include('equipment.partials.rank-label', ['item' => $ci->item])
                <h4 class="text-sm font-bold text-slate-900 leading-snug">{{ $displayName }}</h4>
                @if($ci->is_equipped)
                    <span class="equipment-equipped-badge shrink-0 text-[10px] bg-amber-200 text-amber-800 px-1.5 py-0.5 rounded font-bold">装備中</span>
                @endif
                @if($ci->hasAffix())
                    <span class="shrink-0 text-[10px] bg-indigo-100 text-indigo-700 border border-indigo-200 px-1.5 py-0.5 rounded font-bold">銘付き</span>
                @endif
            </div>

            {{-- カテゴリ・入手元 --}}
            @if($ci->acquired_from === 'drop' || $categoryLabel)
                <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                    @if($ci->acquired_from === 'drop')
                        <span class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded font-bold">ドロップ品</span>
                    @endif
                    @if($categoryLabel)
                        <span class="text-[10px] text-slate-400 font-bold">{{ $categoryLabel }}</span>
                    @endif
                </div>
            @endif
        </div>

        {{-- ステータス行 --}}
        <div class="text-xs font-semibold text-amber-600 leading-relaxed">
            @if($totalStats['hp'] > 0) HP +{{ $totalStats['hp'] }} @endif
            @if($totalStats['str'] > 0) 攻撃 +{{ $totalStats['str'] }} @endif
            @if($totalStats['def'] > 0) 防御 +{{ $totalStats['def'] }} @endif
            @if($totalStats['agi'] > 0) 敏捷 +{{ $totalStats['agi'] }} @endif
            @if($totalStats['mag'] > 0) 魔力 +{{ $totalStats['mag'] }} @endif
            @if($totalStats['spr'] > 0) 精神 +{{ $totalStats['spr'] }} @endif
            @if($totalStats['luk'] > 0) 運 +{{ $totalStats['luk'] }} @endif
            @if($totalStats['agi'] < 0) 敏捷 {{ $totalStats['agi'] }} @endif
        </div>

        @if(!empty($affixLines))
            <div class="flex flex-wrap gap-1">
                @foreach($affixLines as $line)
                    <span class="rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700">{{ $line }}</span>
                @endforeach
            </div>
        @endif

        {{-- 装備不可メッセージ --}}
        @if(!$canEquipByJob)
            <div class="text-[11px] font-bold text-rose-500 leading-snug">
                現在の職業では装備できません
                @if(!empty($restrictionJobs))
                    <span class="text-slate-400 font-medium">（例：{{ implode('、', $restrictionJobs) }}）</span>
                @endif
            </div>
        @endif

        {{-- アクションボタン行（2列） --}}
        <div class="grid grid-cols-2 gap-1.5">
            {{-- 装備/解除ボタン --}}
            @if($canEquipByJob || $ci->is_equipped)
                <form action="{{ $ci->is_equipped ? route('equipment.unequip', $ci) : route('equipment.equip', $ci) }}" method="POST"
                      class="equipment-action-form equipment-toggle-form" data-equipment-action="{{ $ci->is_equipped ? 'unequip' : 'equip' }}">
                    @csrf
                    <button type="submit" data-action-button
                        class="w-full h-8 text-xs font-bold rounded text-white transition-all duration-150 active:scale-95
                               {{ $ci->is_equipped ? 'bg-amber-500 hover:bg-amber-600' : 'bg-amber-600 hover:bg-amber-700' }}">
                        {{ $ci->is_equipped ? 'はずす' : '装備する' }}
                    </button>
                </form>
            @else
                <button type="button" disabled
                    class="w-full h-8 text-xs font-bold rounded bg-slate-100 text-slate-400 cursor-not-allowed">
                    装備不可
                </button>
            @endif

            {{-- 売却ボタン --}}
            <form action="{{ route('equipment.sell', $ci) }}"
                  method="POST"
                  class="equipment-action-form"
                  data-equipment-action="sell"
                  x-data="{ confirmSell: false }"
                  @submit="confirmSell = false">
                @csrf
                <button type="button"
                    data-action-button
                    @click="confirmSell = true"
                    @if(!($ci->can_sell ?? false)) disabled title="{{ $ci->is_equipped ? '装備中は売却不可' : ($ci->is_locked ? '保護中は売却不可' : '売却不可') }}" @endif
                    class="w-full h-8 text-xs font-bold rounded transition-all duration-150 active:scale-95
                           {{ ($ci->can_sell ?? false) ? 'bg-orange-600 text-white hover:bg-orange-700' : 'bg-slate-100 text-slate-400 cursor-not-allowed' }}">
                    @if($sellPrice > 0) 売却する @else 売却不可 @endif
                </button>
                @if(($ci->can_sell ?? false) && $sellPrice > 0)
                    <div x-show="confirmSell"
                         x-cloak
                         @keydown.escape.window="confirmSell = false"
                         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 py-6">
                        <div class="w-full max-w-sm rounded-xl border border-orange-200 bg-white p-4 shadow-2xl"
                             @click.outside="confirmSell = false">
                            <div class="text-sm font-extrabold text-slate-900">装備を売却しますか？</div>
                            <div class="mt-2 rounded-lg bg-orange-50 px-3 py-2 text-sm text-slate-700">
                                <div class="font-bold text-slate-900">{{ $displayName }}</div>
                                <div class="mt-1 text-orange-700">売却額: <span class="font-extrabold">{{ number_format($sellPrice) }}G</span></div>
                            </div>
                            <p class="mt-3 text-xs leading-relaxed text-slate-500">
                                売却した装備は所持品からなくなります。よろしければ確定してください。
                            </p>
                            <div class="mt-4 flex items-center justify-end gap-2">
                                <button type="button"
                                        @click="confirmSell = false"
                                        class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                    キャンセル
                                </button>
                                <button type="submit"
                                        class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-extrabold text-white shadow-sm hover:bg-orange-700">
                                    売却する
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </form>
        </div>

    </div>
</div>
