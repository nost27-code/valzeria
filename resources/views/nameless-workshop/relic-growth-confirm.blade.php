<x-layouts.facility title="遺物育成の確認" :compact-header="true" main-content-class="py-2" :show-exit="false">
    @include('nameless-workshop.style')
    <div class="nw" x-data="{ submitting: false }"><section class="card stack" data-nameless-relic-growth-confirm>
        <div class="row"><x-relic-icon :effect-key="$preview['effect_key']" :size="64" /><h2>{{ $preview['name_before'] }}を育てる</h2></div>
        <p>育成進捗 {{ $preview['progress_before'] }}／{{ $preview['required'] }} → <strong>{{ $preview['filled_progress'] }}／{{ $preview['required'] }}</strong></p>
        @if($preview['rank_up'])<p><strong>{{ $preview['name_after'] }}へランクアップします。</strong></p><p>{{ $preview['summary_before'] }} → {{ $preview['summary_after'] }}</p>@else<p>今回は進捗を保存します。必要数が揃うと次ランクへ上がります。</p>@endif
        <h3>消費する素材遺物（{{ $preview['source_count'] }}個）</h3>
        @foreach($preview['sources'] as $source)<div class="line row"><x-relic-icon :effect-key="$source['effect_key']" /><span>{{ $source['name'] }} ×1</span></div>@endforeach
        <p class="muted">育成する本体は残ります。表示した素材遺物だけが消滅します。成功率100%、Goldは不要です。</p>
        <form method="post" action="{{ route('nameless-workshop.act', 'grow-relic') }}" class="stack" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
            @csrf
            @include('nameless-workshop.equipment-context')
            @foreach(['relic_id', 'request_uuid'] as $key)<input type="hidden" name="{{ $key }}" value="{{ $input[$key] }}">@endforeach
            @if(isset($input['ordinary_context']))<input type="hidden" name="ordinary_context" value="{{ $input['ordinary_context'] }}">@endif
            @if(isset($input['equipment_id']))<input type="hidden" name="equipment_id" value="{{ $input['equipment_id'] }}">@endif
            <input type="hidden" name="workshop_tab" value="sets"><input type="hidden" name="effect" value="{{ $input['effect'] ?? '' }}">
            @foreach($input['source_relics'] as $id)<input type="hidden" name="source_relics[]" value="{{ $id }}">@endforeach
            <input type="hidden" name="confirmation_hash" value="{{ $preview['confirmation_hash'] }}">
            <label class="check"><input type="checkbox" name="confirmed" value="1" required>素材遺物が消費されることを確認しました</label>
            <button class="full" :disabled="submitting"><span x-show="!submitting">{{ $preview['rank_up'] ? 'ランクアップを確定する' : '素材を投入して進捗を保存する' }}</span><span x-show="submitting" x-cloak role="status">育成しています…</span></button>
        </form>
        <a class="button secondary full" href="{{ $backUrl }}" x-show="!submitting">消費せず戻る</a>
    </section></div>
</x-layouts.facility>
