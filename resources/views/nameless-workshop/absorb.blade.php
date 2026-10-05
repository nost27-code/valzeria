@if($selectableEquipment->isNotEmpty())
<details class="card stack feed-card" id="absorb" x-data="{}">
    <summary>不要な遺物を強化に使う</summary>
    <p class="muted">余った遺物を消費すると、強化時に必要な素材が減ります。遺物の効果は本体へ残りません。</p>
    <form method="post" action="{{ route('nameless-workshop.act', 'preview-feed') }}" class="stack">
        @include('nameless-workshop.token')
        <p class="muted">上の絞り込み・並び順を引き継いでいます。消費前に対象の武具を確認してください。</p>
        <label>育てる本体<span class="select-shell"><select name="equipment_id" required x-data="{ renamed: false }" x-init="renamed = $el.selectedOptions[0]?.dataset.renamed === '1'" @change="renamed = $el.selectedOptions[0]?.dataset.renamed === '1'" :class="renamed ? 'renamed-equipment' : ''">@foreach($selectableEquipment as $body)<option data-renamed="{{ $body->isRenamed() ? 1 : 0 }}" style="color:{{ $body->isRenamed() ? '#1d4ed8' : '#213b56' }}" value="{{ $body->id }}" @selected($selectedEquipment?->id === $body->id) @disabled($body->forge_level >= 99)>{{ $body->rankedDisplayName() }} · #{{ $body->id }}{{ $body->forge_level >= 99 ? '（最大強化済み）' : '' }}{{ !$filteredEquipment->has($body->id) ? '（条件対象外）' : '' }}</option>@endforeach</select></span></label>
        <input type="hidden" name="keep" value="0">
        <input type="hidden" name="protect_best" value="0"><label class="check"><input type="checkbox" name="protect_best" value="1" checked>各効果の最高ランクを1つ残す</label>
        <details><summary>遺物を選ぶ（保護中・装着中を除く）</summary>
            <button type="button" class="secondary" x-on:click="$root.querySelectorAll('[data-feed-rank]').forEach(el => { el.checked = Number(el.dataset.feedRank) <= 3 &amp;&amp; el.dataset.best === '0'; })">余剰のI〜IIIを選ぶ</button>
            <div class="scroll">
                @forelse($relics->where('is_locked', false)->reject(fn ($relic) => $relic->isAttached()) as $relic)
                    <label class="check line"><input type="checkbox" name="relics[]" value="{{ $relic->id }}" data-feed-rank="{{ $relic->rank }}" data-best="{{ in_array($relic->id, $bestIds, true) ? 1 : 0 }}"><x-relic-icon :effect-key="$relic->effect_key" /><span class="relic-copy">{{ $relic->displayName() }}<small>{{ $relic->effectSummary() }} @if(in_array($relic->id, $bestIds, true))· 最高ランク保持の対象@endif</small></span></label>
                @empty<p class="muted">吸収できる遺物はありません。</p>@endforelse
            </div>
        </details>
        <button class="full" @disabled($selectableEquipment->every(fn ($body) => $body->forge_level >= 99))>消費する内容を確認する</button>
    </form>
</details>
@endif
