@if($equipment->isNotEmpty())
<details class="card stack" id="bulk-equipment-discard" data-equipment-bulk-discard @if($discardSelectedIds || old('equipment_ids')) open @endif>
    <summary>名もなき武具をまとめて整理する</summary>
    <p class="muted">上の検索・絞り込みに合う武具から選びます。初期配布・保護中・装備中・遺物装着中の武具は対象外です。</p>
    @if($discardableEquipment->isNotEmpty())
    <form method="post" action="{{ route('nameless-workshop.act', 'preview-discard-equipment-bulk') }}" class="stack"
        x-data="{ selected: @js($discardSelectedIds), choices: @js($discardableEquipment->keys()->map('strval')->values()), maxSelected: {{ \App\Services\NamelessEquipmentBulkDiscardService::MAX_SELECTION }}, submitting: false }"
        @pageshow.window="submitting = false" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
        @include('nameless-workshop.token')
        @if($selectedEquipment)<input type="hidden" name="equipment_id" value="{{ $selectedEquipment->id }}">@endif
        <small>一度に{{ \App\Services\NamelessEquipmentBulkDiscardService::MAX_SELECTION }}個まで選べます。多い場合は分けて整理できます。</small>
        <div class="row bulk-actions"><button type="button" class="secondary" @click="selected = choices.slice(0, maxSelected)" :disabled="submitting">対象を一括選択</button><button type="button" class="secondary" @click="selected = []" :disabled="submitting">選択をすべて解除</button></div>
        <small role="status" x-text="'選択中 ' + selected.length + ' 個'">選択中 {{ count($discardSelectedIds) }} 個</small>
        <div class="scroll">
            @foreach($discardableEquipment as $body)
            <label class="check line bulk-equipment-choice"><input type="checkbox" name="equipment_ids[]" value="{{ $body->id }}" x-model="selected" :disabled="selected.length >= maxSelected && !selected.includes('{{ $body->id }}')" @checked(in_array((string) $body->id, $discardSelectedIds, true)) @click="if (submitting) $event.preventDefault()"><span class="relic-copy"><strong class="{{ $body->isRenamed() ? 'renamed-equipment' : '' }}">{{ $body->rankedDisplayName() }}</strong><small>{{ $body->kindLabel() }} · #{{ $body->id }} · 成長EXP {{ number_format($body->growth_exp) }}</small></span></label>
            @endforeach
        </div>
        <button class="danger full" :disabled="submitting || selected.length === 0"><span x-show="!submitting">選んだ武具の破棄内容を確認する</span><span x-show="submitting" x-cloak role="status">破棄内容を確認しています…</span></button>
        <small>ここでは消費しません。次の画面で対象と失われる育成状態を確認します。</small>
    </form>
    @else<p class="muted">この条件で破棄できる武具はありません。絞り込みや保護・装備・遺物の装着状態を確認してください。</p>@endif
</details>
@endif
