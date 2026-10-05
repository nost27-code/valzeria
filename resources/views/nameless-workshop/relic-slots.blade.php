@php
    $ordinaryTarget = $body instanceof \App\Models\CharacterItem;
    $relicSlotCount = app(\App\Services\NamelessRelicEquipmentService::class)->slotsFor($body);
    $relicTargetField = $ordinaryTarget ? 'character_item_id' : 'equipment_id';
@endphp
<div class="stack" data-nameless-relic-slots>
    <p class="muted">遺物は{{ $relicSlotCount }}つまで。同じ効果は一本の武具と、装備中の武器・防具・装飾品を通じて1つまで。種族刻印・変身・通常攻撃の変換は、それぞれ一種類まで。</p>
    @for($slot = 1; $slot <= $relicSlotCount; $slot++)
        @php($attached = $body->relics->firstWhere('slot_number', $slot))
        <details class="relic-slot">
            <summary><span class="slot-number">{{ $slot }}</span>@if($attached)<x-relic-icon :effect-key="$attached->effect_key" />@endif<span class="relic-copy">{{ $attached?->displayName() ?? '遺物をセットする' }}@if($attached)<small>{{ $attached->effectSummary() }}</small>@else<small>空き枠</small>@endif</span></summary>
            @if($attached)<p class="muted">{{ $catalog->definition($attached->effect_key)['description'] }}</p>@endif
            <form method="post" action="{{ route('nameless-workshop.act', $ordinaryTarget ? 'attach-ordinary' : 'attach') }}" class="stack" x-data="{ selectedRelic: '' }">
                @include('nameless-workshop.token')<input type="hidden" name="{{ $relicTargetField }}" value="{{ $body->id }}"><input type="hidden" name="slot" value="{{ $slot }}">
                <label>装着する遺物<span class="select-shell"><select name="relic_id" required x-model="selectedRelic">
                    <option value="">遺物を選ぶ</option>@foreach($relics->reject(fn ($relic) => $relic->isAttached()) as $relic)<option value="{{ $relic->id }}">{{ $relic->displayName() }} · {{ $relic->effectSummary() }}{{ $relic->is_locked ? '（保護）' : '' }}</option>@endforeach
                </select></span></label>
                <template x-if="relicDescriptions[selectedRelic]?.image_url"><div class="row"><img :src="relicDescriptions[selectedRelic].image_url" alt="" width="160" height="160" decoding="async" style="width:64px;height:64px;object-fit:contain;flex-shrink:0"><strong class="relic-copy" x-text="relicDescriptions[selectedRelic].name"></strong></div></template>
                <details class="effect-preview"><summary>選んだ遺物の効果を見る</summary><strong x-text="relicDescriptions[selectedRelic]?.summary ?? '遺物を選んでください'"></strong><p class="muted" x-text="relicDescriptions[selectedRelic]?.description ?? ''"></p></details>
                <button class="full">{{ $attached ? '入れ替える（元の遺物は手元へ）' : 'この遺物をセットする' }}</button>
            </form>
            @if($attached)<form method="post" action="{{ route('nameless-workshop.act', 'detach') }}">@include('nameless-workshop.token')<input type="hidden" name="relic_id" value="{{ $attached->id }}"><input type="hidden" name="{{ $relicTargetField }}" value="{{ $body->id }}"><button class="secondary full">取り外す</button></form>@endif
        </details>
    @endfor
</div>
