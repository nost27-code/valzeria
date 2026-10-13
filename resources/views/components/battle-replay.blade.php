@props(['replay' => null, 'playerImage' => null, 'enemyImage' => null, 'batchRequested' => 1, 'batchCompleted' => 1])
@if(config('battle.replay.enabled') && ($replay['version'] ?? null) === 1 && !empty($replay['frames']))
    @include('battle.partials.battle-replay-assets')
    <section class="battle-replay" data-battle-replay aria-label="戦闘の再生">
        <script type="application/json" data-replay-data>@json($replay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
        <div class="br-heading"><strong>{{ $batchRequested > 1 ? '最後の戦闘' : '戦闘の記録' }}</strong><span data-replay-turn>開始前</span><label><input type="checkbox" data-replay-effects checked> 光の演出</label></div>
        @if($batchRequested > 1)
            <p class="br-batch-note">{{ number_format($batchCompleted) }}回探索のうち、最後の1戦を再生します。</p>
        @endif
        <div class="br-field">
            @foreach(['player' => $playerImage, 'enemy' => $enemyImage] as $side => $image)
                @php($actor = $replay['initial'][$side])
                <div class="br-fighter" data-side="{{ $side }}">
                    <div class="br-name">{{ $actor['name'] }}</div>
                    @foreach(['hp' => 'HP', 'sp' => 'SP'] as $key => $label)
                        <div class="br-vital"><span>{{ $label }}</span><span data-value="{{ $key }}">{{ number_format($actor[$key]) }}/{{ number_format($actor[$key === 'hp' ? 'maxHp' : 'maxSp']) }}</span></div>
                        <div class="br-track" data-meter="{{ $key }}"><span></span></div>
                    @endforeach
                    <div class="br-character">
                        <div class="br-motion"><div class="br-portrait">
                            @if($image)<img src="{{ $image }}" alt="{{ $actor['name'] }}" class="br-art" decoding="async">
                            @else<span class="br-no-image">{{ $actor['name'] }}</span>@endif
                        </div></div>
                        <div class="br-badges" data-replay-badges></div>
                    </div>
                    <div class="br-resources" data-replay-resources></div>
                </div>
            @endforeach
        </div>
        <div class="br-current battle-log-entry" data-replay-log aria-live="polite" aria-atomic="true">「再生」で戦闘の経過を確認できます。</div>
        <div class="br-controls">
            <button type="button" data-replay-play>再生</button>
            <button type="button" data-replay-previous>前へ</button>
            <button type="button" data-replay-step>次へ</button>
            <button type="button" data-replay-speed aria-label="再生速度">1×</button>
            <button type="button" data-replay-result>結果を見る</button>
        </div>
        <noscript>戦闘の経過は下の戦闘ログで確認できます。</noscript>
    </section>
@endif
