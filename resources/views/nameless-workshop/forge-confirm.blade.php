@php($backUrl = route('nameless-workshop.index', ($equipmentFilterQuery ?? []) + ['tab' => 'workshop', 'equipment' => $preview['equipment_id']]).'#forge')
<x-layouts.facility title="強化内容の確認" :compact-header="true" main-content-class="py-2" :show-exit="false">
    @include('nameless-workshop.style')
    <div class="nw"><section class="card stack" data-nameless-forge-confirm>
        <h2><x-nameless-equipment-badge /> <span class="{{ $preview['equipment_renamed'] ? 'renamed-equipment' : '' }}">{{ $preview['equipment_name'] }}を +{{ $preview['next_level'] }} に強化</span></h2>
        <div class="forge-price"><span>強化費用</span><strong>{{ number_format($preview['gold']) }} <small>G</small></strong></div>
        <p class="stats" data-nameless-forge-performance>{{ $preview['performance_label'] }}</p>
        @if($preview['use_bank'])<p class="muted">手持ちで足りない分は預金から支払います。</p>@endif
        <h3>消費する素材・遺物</h3>
        <div class="scroll">@forelse($preview['sources'] as $source)<div class="line row between">@if($source['kind'] === 'relic')<x-relic-icon :effect-key="$source['effect_key'] ?? null" />@endif<span class="relic-copy">{{ $source['name'] }} ×{{ number_format($source['quantity']) }}</span><small>{{ number_format($source['exp']) }} pt</small></div>@empty<p>持ち越し分を使うため、追加の素材・遺物は消費しません。</p>@endforelse</div>
        <p>次の強化へ持ち越す：<strong>{{ number_format($preview['exp_after']) }} pt</strong></p>
        <p class="muted">表示した個数を消費します。<strong>遺物は失われ、効果は武具に残りません。</strong></p>
        <form method="post" action="{{ route('nameless-workshop.act', 'forge-combined') }}" class="stack">
            @csrf
            @include('nameless-workshop.equipment-context')
            @foreach(['equipment_id', 'revision', 'protect_best', 'request_uuid'] as $key)<input type="hidden" name="{{ $key }}" value="{{ $input[$key] }}">@endforeach
            <input type="hidden" name="workshop_tab" value="workshop"><input type="hidden" name="use_bank" value="{{ $preview['use_bank'] ? 1 : 0 }}">
            @foreach(($input['materials'] ?? []) as $id => $quantity)<input type="hidden" name="materials[{{ $id }}]" value="{{ $quantity }}">@endforeach
            @foreach(($input['relics'] ?? []) as $id)<input type="hidden" name="relics[]" value="{{ $id }}">@endforeach
            <input type="hidden" name="confirmation_hash" value="{{ $preview['confirmation_hash'] }}">
            <label class="check"><input type="checkbox" name="confirmed" value="1" required>この内容で消費することを確認しました</label>
            <button class="full">{{ number_format($preview['gold']) }} Gを払って +{{ $preview['next_level'] }} に強化する</button>
        </form>
        <a class="button secondary full" href="{{ $backUrl }}">消費せず戻る</a>
    </section></div>
</x-layouts.facility>
