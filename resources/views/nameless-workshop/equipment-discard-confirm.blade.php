<x-layouts.facility title="武具の一括破棄を確認" :compact-header="true" main-content-class="py-2" :show-exit="false">
    @include('nameless-workshop.style')
    <div class="nw"><section class="card stack" data-equipment-bulk-discard-confirm x-data="{ submitting: false }" @pageshow.window="submitting = false">
        <h2>名もなき武具 {{ $preview['count'] }}個を破棄する</h2>
        <div class="scroll">@foreach($preview['items'] as $item)<div class="line bulk-equipment-choice"><strong>{{ $item['name'] }} +{{ $item['forge_level'] }}</strong><small>#{{ $item['id'] }} · 成長EXP {{ number_format($item['growth_exp']) }}</small></div>@endforeach</div>
        <p class="error">表示した武具と育成状態、成長EXP 合計{{ number_format($preview['growth_exp']) }}を失います。育成に使った素材・遺物・Goldは戻りません。</p>
        <p class="muted">発見記録は残ります。破棄によるポイントやGoldは得られません。</p>
        <form method="post" action="{{ route('nameless-workshop.act', 'discard-equipment-bulk') }}" class="stack" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
            @csrf
            @include('nameless-workshop.equipment-context')
            @foreach(['request_uuid', 'workshop_tab', 'equipment_id', 'ordinary_context'] as $key)@if(isset($input[$key]))<input type="hidden" name="{{ $key }}" value="{{ $input[$key] }}">@endif @endforeach
            @foreach($input['equipment_ids'] as $id)<input type="hidden" name="equipment_ids[]" value="{{ $id }}">@endforeach
            <input type="hidden" name="confirmation_hash" value="{{ $preview['confirmation_hash'] }}">
            <label class="check"><input type="checkbox" name="confirmed" value="1" required>この{{ $preview['count'] }}個と育成状態を失うことを確認しました</label>
            <button class="danger full" :disabled="submitting"><span x-show="!submitting">{{ $preview['count'] }}個の武具の破棄を確定する</span><span x-show="submitting" x-cloak role="status">破棄しています…</span></button>
        </form>
        <a class="button secondary full" href="{{ $backUrl }}" @click="if (submitting) $event.preventDefault()" :aria-disabled="submitting">破棄せず選び直す</a>
    </section></div>
</x-layouts.facility>
