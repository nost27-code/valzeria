<section class="card stack" id="relic-growth" data-nameless-relic-growth>
    <div class="row"><x-relic-icon :effect-key="$target->effect_key" :size="64" /><h2>{{ $target->displayName() }}を育てる</h2></div>
    @if($growth['can_grow'])
        <div class="forge-progress">
            <div class="row between"><strong>育成進捗</strong><span>{{ $growth['progress'] }}／{{ $growth['required'] }}</span></div>
            <div class="forge-progress-track" role="progressbar" aria-label="遺物の育成進捗" aria-valuemin="0" aria-valuemax="{{ $growth['required'] }}" aria-valuenow="{{ $growth['progress'] }}"><span class="forge-progress-fill" style="width:{{ $growth['percent'] }}%"></span></div>
            <p>ランク{{ $growth['next_label'] }}まで、同じ効果・同じランクの素材あと<strong>{{ $growth['remaining'] }}個</strong>。</p>
        </div>
        <p>{{ $target->effectSummary() }}</p>
        <p><strong>ランク{{ $growth['next_label'] }}：{{ $growth['next_summary'] }}</strong></p>
        <p class="muted">本体は残り、投入した素材だけが消費されます。成功率100%。Goldは不要です。保護中・装着中の本体も育成でき、途中の進捗は保存されます。</p>
        @if($growthCandidates->isNotEmpty())
            <form method="post" action="{{ route('nameless-workshop.act', 'preview-relic-growth') }}" class="stack" x-data="{ selected: [] }">
                @include('nameless-workshop.token')
                <input type="hidden" name="relic_id" value="{{ $target->id }}">
                @if($selectedEquipment)<input type="hidden" name="equipment_id" value="{{ $selectedEquipment->id }}">@endif
                <input type="hidden" name="effect" value="{{ $filterEffect }}">
                <h3>投入する素材を選ぶ（1〜{{ $growth['remaining'] }}個）</h3>
                <div class="material-choices">
                    @foreach($growthCandidates as $source)
                        <label class="material-choice"><input type="checkbox" name="source_relics[]" value="{{ $source->id }}" x-model="selected" :disabled="selected.length >= {{ $growth['remaining'] }} && !selected.includes('{{ $source->id }}')"><span><strong>{{ $source->displayName() }}</strong><small>素材候補{{ $loop->iteration }} · 未装着・未保護</small></span></label>
                    @endforeach
                </div>
                <button class="full" :disabled="selected.length === 0">育成内容を確認する</button>
            </form>
        @else<p class="muted">投入できる同じ効果・同じランクの遺物がありません。保護中・装着中・育成途中の遺物は素材に使えません。</p>@endif
    @else<p>この遺物は最大ランクIXです。これ以上の育成や素材消費はできません。</p>@endif
</section>
