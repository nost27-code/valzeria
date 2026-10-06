@if($selectedRelicGrowth)
    @include('nameless-workshop.relic-growth', ['target' => $selectedRelicGrowth, 'growth' => $relicGrowth->get($selectedRelicGrowth->id)])
@endif
<section class="card stack">
    <div class="row between"><h2>所持遺物 {{ $relics->count() }}個</h2>@if($relicFeedEquipment)<a class="link" href="{{ route('nameless-workshop.index', $equipmentFilterQuery + ['tab' => 'workshop', 'equipment' => $relicFeedEquipment->id]).'#forge' }}">不要な遺物を吸収する</a>@endif</div>
    <p class="muted">素材倉庫 {{ number_format($storageSummary['material_total']) }} / {{ number_format($storageSummary['material_limit']) }} · 通常素材と共通の枠を使います。</p>
    <form method="get" class="row"><input type="hidden" name="tab" value="sets">@include('nameless-workshop.equipment-context')@if($selectedEquipment)<input type="hidden" name="equipment" value="{{ $selectedEquipment->id }}">@endif @if($selectedOrdinaryEquipment)<input type="hidden" name="character_item" value="{{ $selectedOrdinaryEquipment->id }}">@endif<label style="flex:1">効果で絞り込み<span class="select-shell"><select name="effect"><option value="">すべての効果</option>@foreach($effectGroups as $groupLabel => $groupEffects)<optgroup label="{{ $groupLabel }}">@foreach($groupEffects as $key => $effect)<option value="{{ $key }}" @selected($filterEffect === $key)>{{ $effect['name'] }}</option>@endforeach</optgroup>@endforeach</select></span></label><button class="secondary">表示する</button></form>
    <p class="muted">同じ効果・同じランクなら強さは同じです。保護した遺物は吸収されません。効果は通常探索・ボス・遺跡と、闘技場・六英雄戦で発動します。勝利後回復はPvEのみ。能力は遺物を除く装備込み能力を基準に増加し、能力ごとの増加上限は30%です。</p>
    @if($relicGrowth->isNotEmpty())<p class="muted">同じ効果・同じランクを使って遺物を育成できます。I〜VIは本体＋素材2個、VII・VIIIは本体＋素材4個で次ランクへ。素材は1個ずつ投入でき、途中の進捗も保存されます。</p>@endif
</section>
<div class="relic-grid">
    @forelse($relics->filter(fn ($relic) => !$filterEffect || $relic->effect_key === $filterEffect) as $relic)
        @php($growth = $relicGrowth->get($relic->id))
        <details class="card stack relic-entry" id="relic-{{ $relic->id }}" x-data="{ discarding: false }">
            <summary @click="if (discarding) $event.preventDefault()"><x-relic-icon :effect-key="$relic->effect_key" :size="64" /><span class="relic-copy"><strong>{{ $relic->displayName() }}</strong><small>{{ $relic->effectSummary() }}</small></span></summary><p class="muted">{{ $catalog->definition($relic->effect_key)['description'] }}</p>
            <div class="row">@if($relic->isAttached())<span class="badge">{{ ($relic->equipment?->is_equipped || $relic->characterItem?->is_equipped) ? '装備中の武具に装着' : '控えの武具に装着' }}</span>@endif @if($relic->is_locked)<span class="badge gold">保護中</span>@endif @if(in_array($relic->id, $bestIds, true))<span class="badge">効果ごとの最高ランク</span>@endif</div>
            @if($growth)
                @if($growth['can_grow'])
                    <p class="stats">育成進捗 {{ $growth['progress'] }}／{{ $growth['required'] }} · ランク{{ $growth['next_label'] }}まで素材あと{{ $growth['remaining'] }}個</p>
                    <a class="button secondary full" href="{{ route('nameless-workshop.index', $equipmentFilterQuery + array_filter(['tab' => 'sets', 'equipment' => $selectedEquipment?->id, 'character_item' => $selectedOrdinaryEquipment?->id, 'effect' => $filterEffect, 'grow_relic' => $relic->id])).'#relic-growth' }}">この遺物を育てる</a>
                @else<span class="badge gold">最大ランクIX</span>@endif
            @endif
            @if($relic->equipment && (int) $relic->equipment->character_id === (int) $character->id)
                <a class="link {{ $relic->equipment->isRenamed() ? 'renamed-equipment' : '' }}" href="{{ route('nameless-workshop.index', $equipmentFilterQuery + ['tab' => 'sets', 'equipment' => $relic->equipment->id]) }}">装着先：{{ $relic->equipment->rankedDisplayName() }} · 遺物枠{{ $relic->slot_number }}</a>
            @endif
            @if($relic->characterItem && (int) $relic->characterItem->character_id === (int) $character->id)
                <a class="link" href="{{ route('nameless-workshop.index', ['tab' => 'sets', 'character_item' => $relic->character_item_id]) }}">装着先：{{ $relic->characterItem->displayName() }} · 遺物枠{{ $relic->slot_number }}</a>
            @endif
            @if($relic->growth_progress)<small>育成途中の遺物は素材・吸収に使えません。余剰品は確認のうえ進捗ごと破棄できます。</small>@else<small>吸収すると成長EXP {{ number_format($catalog->feedExp($relic->rank)) }}</small>@endif
            <form method="post" action="{{ route('nameless-workshop.act', 'protect') }}">
                @include('nameless-workshop.token')<input type="hidden" name="relic_id" value="{{ $relic->id }}"><input type="hidden" name="protected" value="{{ $relic->is_locked ? 0 : 1 }}">
                <button class="secondary full" :disabled="discarding">{{ $relic->is_locked ? '保護を解除する' : '保護する' }}</button>
            </form>
            @if(!$relic->is_locked && !$relic->isAttached() && !in_array($relic->id, $bestIds, true))
                <details>
                    <summary @click="if (discarding) $event.preventDefault()">{{ $relic->growth_progress ? '進捗ごと破棄する' : '余剰品を破棄する' }}</summary>
                    @if($relic->growth_progress)
                        <p class="muted" data-relic-discard-progress-warning>この遺物と育成進捗 {{ $growth['progress'] }}／{{ $growth['required'] }} は失われます。育成に使った遺物は戻りません。成長EXPも得られません。</p>
                    @else<p class="muted">この遺物は消滅し、成長EXPも得られません。本体を育成中なら吸収に使えます。</p>@endif
                    <form method="post" action="{{ route('nameless-workshop.act', 'discard') }}" class="stack" @submit="if (discarding) { $event.preventDefault(); return; } discarding = true">
                        @include('nameless-workshop.token')<input type="hidden" name="relic_id" value="{{ $relic->id }}">
                        <input type="hidden" name="expected_rank" value="{{ $relic->rank }}"><input type="hidden" name="expected_growth_progress" value="{{ $relic->growth_progress }}">
                        <label class="check"><input type="checkbox" name="confirmed" value="1" required>{{ $relic->displayName() }}{{ $relic->growth_progress ? 'と育成進捗を失う' : 'を破棄する' }}ことを確認しました</label>
                        <button class="danger full" :disabled="discarding"><span x-show="!discarding">破棄を確定する</span><span x-show="discarding" x-cloak role="status">破棄しています…</span></button>
                    </form>
                </details>
            @endif
        </details>
    @empty<section class="card"><p>該当する遺物はありません。遺跡で最初の1つを探しましょう。</p><a class="button" href="{{ route('nameless-workshop.return', ['tab' => 'dungeon']) }}">探索タブへ</a></section>@endforelse
</div>
<details class="card"><summary>遺物の効果図鑑（全{{ count($effects) }}種）</summary>@foreach($effectGroups as $groupLabel => $groupEffects)<details class="line"><summary>{{ $groupLabel }}（{{ count($groupEffects) }}種）</summary>@foreach($groupEffects as $effect)<div class="line row"><x-relic-icon :effect-key="$effect['key']" :size="64" /><div class="relic-copy"><strong>{{ $effect['name'] }}</strong><p class="muted">{{ $effect['description'] }}</p><small>I：{{ $catalog->summary($effect['key'], 1, false) }}<br>IX：{{ $catalog->summary($effect['key'], 9, false) }}</small></div></div>@endforeach</details>@endforeach</details>
