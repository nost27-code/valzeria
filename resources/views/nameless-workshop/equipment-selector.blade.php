@if($equipment->isNotEmpty() || ($tab === 'sets' && $ordinaryEquipment->isNotEmpty()))
<section class="card stack equipment-picker">
    <h2>{{ $tab === 'sets' ? '遺物を付ける装備を選ぶ' : '育てる武具を選ぶ' }}</h2>
    <form method="get" class="stack">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @include('nameless-workshop.equipment-context')
        @if($selectableEquipment->isNotEmpty() || ($tab === 'sets' && $ordinaryEquipment->isNotEmpty()))
        <label class="select-label">所持武具
            <span class="select-shell"><select name="{{ $tab === 'sets' ? 'relic_equipment' : 'equipment' }}" x-data="{ renamed: false }" x-init="renamed = $el.selectedOptions[0]?.dataset.renamed === '1'" @change="renamed = $el.selectedOptions[0]?.dataset.renamed === '1'; $el.form.requestSubmit()" :class="renamed ? 'renamed-equipment' : ''">
                @foreach($selectableEquipment as $body)<option data-renamed="{{ $body->isRenamed() ? 1 : 0 }}" value="{{ $tab === 'sets' ? 'nameless:'.$body->id : $body->id }}" @selected($selectedEquipment?->id === $body->id) style="color:{{ $body->isRenamed() ? '#1d4ed8' : '#213b56' }}">{{ $body->rankedDisplayName() }} · {{ $body->equipment_type }} · #{{ $body->id }}{{ $body->is_equipped ? '（装備中）' : '' }}{{ $body->is_locked ? '（保護）' : '' }}{{ !$filteredEquipment->has($body->id) ? '（条件対象外）' : '' }}</option>@endforeach
                @if($tab === 'sets' && $ordinaryEquipment->isNotEmpty())
                    <optgroup label="通常装備（SSS 2枠・EPIC 3枠）">
                        @foreach($ordinaryEquipment as $ordinary)
                            <option value="ordinary:{{ $ordinary->id }}" @selected($selectedOrdinaryEquipment?->id === $ordinary->id)>[{{ $ordinaryRelics->rank($ordinary->item) }}] {{ $ordinary->displayName(false) }} · #{{ $ordinary->id }}{{ $ordinary->is_equipped ? '（装備中）' : ($ordinary->is_stored ? '（倉庫）' : '（控え）') }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select></span>
        </label>
        <noscript><button class="secondary full">選んだ武具を表示</button></noscript>
        @endif
    </form>
    @if($tab === 'sets')<p class="muted">SSSは2枠、EPIC・名もなきシリーズは3枠。通常装備の武器・防具・装飾品を選べます。</p>@endif
    <details @if($equipmentFilterQuery) open @endif>
        <summary>名もなき武具の検索・絞り込み・並び替え</summary>
        <form method="get" action="{{ route('nameless-workshop.index') }}" class="stack">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if($selectedEquipment)<input type="hidden" name="equipment" value="{{ $selectedEquipment->id }}">@endif
            @if($selectedOrdinaryEquipment)<input type="hidden" name="character_item" value="{{ $selectedOrdinaryEquipment->id }}">@endif
            <label>名前・種類・個体番号で探す<input type="search" name="gear_query" maxlength="64" class="full" value="{{ $equipmentFilters['gear_query'] }}" placeholder="例：星巡り、杖、#12"></label>
            <div class="filter-grid">
                @foreach(['gear_kind' => '武具の区分', 'gear_type' => '武具の種類', 'gear_state' => '装備状態', 'gear_protection' => '保護状態', 'gear_source' => '入手元', 'gear_sort' => '並び順'] as $key => $label)
                    <label>{{ $label }}<span class="select-shell"><select name="{{ $key }}">@foreach($equipmentFilterOptions[$key] as $value => $option)<option value="{{ $value }}" @selected($equipmentFilters[$key] === $value)>{{ $option }}</option>@endforeach</select></span></label>
                @endforeach
            </div>
            <div class="row between"><button class="secondary">条件を適用する</button><a class="link" href="{{ route('nameless-workshop.index', ['tab' => $tab] + ($selectedOrdinaryEquipment ? ['character_item' => $selectedOrdinaryEquipment->id] : []) + ($selectedEquipment ? ['equipment' => $selectedEquipment->id] : [])) }}">条件をリセット</a></div>
        </form>
    </details>
    <small role="status">条件に合う武具 {{ $filteredEquipment->count() }} / 所持 {{ $equipment->count() }} 個</small>
    @if($selectionOutsideFilter)<p class="muted">選択中の武具は条件の対象外です。操作対象はそのまま保持しています。</p>@endif
    @if($filteredEquipment->isEmpty())<p class="muted">条件に合う武具はありません。条件を変更するか、リセットしてください。</p>@endif
</section>
@endif
