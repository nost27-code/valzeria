<x-layouts.facility title="吸収内容の確認" :exit-url="route('nameless-workshop.index', ($equipmentFilterQuery ?? []) + ['equipment' => $preview['equipment_id']]).'#absorb'" exit-label="選び直す">
    @include('nameless-workshop.style')
    <div class="nw"><section class="card stack">
        <h2><x-nameless-equipment-badge /> <span class="{{ $preview['equipment_renamed'] ? 'renamed-equipment' : '' }}">{{ $preview['equipment_name'] }}へ吸収</span></h2>
        <p>成長EXP {{ number_format($preview['exp_before']) }} → <strong>{{ number_format($preview['exp_after']) }}</strong>（+{{ number_format($preview['gained_exp']) }}）</p>
        <div class="scroll">@foreach($preview['sources'] as $source)<div class="line row between">@if($source['kind'] === 'relic')<x-relic-icon :effect-key="$source['effect_key'] ?? null" />@endif<span class="relic-copy">{{ $source['name'] }} ×{{ number_format($source['quantity']) }}</span><strong>{{ number_format($source['exp']) }} EXP</strong></div>@endforeach</div>
        <p class="muted">ここに表示された素材・遺物は消費されます。遺物の能力は引き継がれません。</p>
        <form method="post" action="{{ route('nameless-workshop.act', 'feed') }}" class="stack">
            @csrf
            @include('nameless-workshop.equipment-context')
            @foreach(['equipment_id', 'keep', 'protect_best', 'request_uuid'] as $key)<input type="hidden" name="{{ $key }}" value="{{ $input[$key] }}">@endforeach
            @foreach(($input['materials'] ?? []) as $id => $quantity)<input type="hidden" name="materials[{{ $id }}]" value="{{ $quantity }}">@endforeach
            @foreach(($input['relics'] ?? []) as $id)<input type="hidden" name="relics[]" value="{{ $id }}">@endforeach
            <input type="hidden" name="confirmation_hash" value="{{ $preview['confirmation_hash'] }}">
            <label class="check"><input type="checkbox" name="confirmed" value="1" required>この内容で消費することを確認しました</label>
            <button class="danger full">吸収を確定する</button>
        </form>
        <a class="button secondary full" href="{{ route('nameless-workshop.index', ($equipmentFilterQuery ?? []) + ['equipment' => $preview['equipment_id']]).'#absorb' }}">消費せず戻る</a>
    </section></div>
</x-layouts.facility>
