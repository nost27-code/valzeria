<section class="card stack" data-ordinary-relic-equipment="{{ $body->id }}">
    @php($ordinaryImage = $body->item->iconImagePath())
    <div class="equipment-summary" @if(!$ordinaryImage) style="grid-template-columns:minmax(0,1fr)" @endif>
        @if($ordinaryImage)<img class="equipment-art" src="{{ asset($ordinaryImage) }}" alt="" width="160" height="160" decoding="async">@endif
        <div class="equipment-copy stack">
            <small>通常装備 · {{ ['weapon' => '武器', 'armor' => '防具', 'accessory' => '装飾品'][$body->item->type] }} · #{{ $body->id }}</small>
            <h2>[{{ $ordinaryRelics->rank($body->item) }}] {{ $body->displayName(false) }}</h2>
            <span class="badge {{ $body->is_equipped ? 'gold' : '' }}">{{ $body->is_equipped ? '装備中' : ($body->is_stored ? '倉庫' : '控え') }}</span>
            <form method="post" action="{{ route('nameless-workshop.act', 'equip-ordinary') }}">
                @include('nameless-workshop.token')<input type="hidden" name="character_item_id" value="{{ $body->id }}"><input type="hidden" name="equipped" value="{{ $body->is_equipped ? 0 : 1 }}">
                <button class="secondary">{{ $body->is_equipped ? '装備を外す' : '装備する' }}</button>
            </form>
        </div>
    </div>
    @include('nameless-workshop.relic-slots')
    <p class="muted">控え・倉庫の装備に付けた遺物は発動しません。遺物は取り外して再利用できます。進化では選んだ進化元の遺物を引き継ぎます。売却・出品・素材化の前には取り外してください。</p>
</section>
