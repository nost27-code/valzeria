@include('nameless-workshop.equipment-selector')
@if($selectedOrdinaryEquipment && $tab === 'sets')
    @include('nameless-workshop.ordinary-equipment', ['body' => $selectedOrdinaryEquipment])
@endif
@if($selectedEquipment)
    @php($body = $selectedEquipment)
    @php($kind = $body->kind)
    @php($label = $body->kindLabel())
    <section class="card stack" data-nameless-equipment="{{ $body->id }}">
        <div class="equipment-summary">
            @if($body->imagePath())<img class="equipment-art" src="{{ asset($body->imagePath()) }}" alt="{{ $body->displayName() }}" width="300" height="300" decoding="async">@endif
            <div class="equipment-copy stack">
                <small>{{ $body->acquisition_source === 'ruin' ? '遺跡で発見' : '初期配布' }} · {{ $body->equipment_type }}</small>
                <h2><x-nameless-equipment-badge /> <span class="{{ $body->isRenamed() ? 'renamed-equipment' : '' }}">{{ $body->displayName() }} +{{ $body->forge_level }}</span></h2>
                <details class="equipment-rename" data-nameless-rename><summary>名前を変更</summary>
                    <form method="post" action="{{ route('nameless-workshop.act', 'rename') }}" class="stack">
                        @include('nameless-workshop.token')<input type="hidden" name="equipment_id" value="{{ $body->id }}">
                        <label>武具の名前<input class="full" name="name" maxlength="32" value="{{ $body->custom_name }}" autocomplete="off" placeholder="{{ '名もなき'.$body->equipment_type }}"></label>
                        <small>32文字まで。空欄で元の名前に戻せます。</small><button class="secondary full">名前を保存する</button>
                    </form>
                </details>
                <div class="row"><span class="badge {{ $body->is_equipped ? 'gold' : '' }}">{{ $body->is_equipped ? '装備中' : '控え' }}</span><span class="stats">{{ $body->kindLabel().'性能 ' }}{{ $body->performanceLabel() }}</span></div>
                <form method="post" action="{{ route('nameless-workshop.act', 'equip') }}">@include('nameless-workshop.token')<input type="hidden" name="equipment_id" value="{{ $body->id }}"><input type="hidden" name="equipped" value="{{ $body->is_equipped ? 0 : 1 }}"><button class="secondary">{{ $body->is_equipped ? '装備を外す' : '装備する' }}</button></form>
            </div>
        </div>
        @if($tab === 'workshop')
            @include('nameless-workshop.forge')
        @else
            @include('nameless-workshop.relic-slots')
        @endif
        <details class="equipment-details"><summary>{{ $body->acquisition_source === 'starter' ? '形・武具の管理' : '武具の管理' }}</summary>
            <small>通常の{{ $label }}と持ち替えて使います。控えの武具の能力・遺物効果は発動しません。</small>
            @if($body->acquisition_source === 'starter')
            <details><summary>形を変更</summary><form method="post" action="{{ route('nameless-workshop.act', 'configure') }}" class="stack">@include('nameless-workshop.token')<input type="hidden" name="equipment_id" value="{{ $body->id }}"><input type="hidden" name="name" value="{{ $body->custom_name }}"><label>形<select name="type">@foreach($types[$kind] as $type => $stat)<option value="{{ $type }}" @selected($type === $body->equipment_type)>{{ $type }}（{{ $stat['label'] }}）</option>@endforeach</select></label><button class="secondary full">形を変更する</button></form></details>
            @endif
        <section class="slot stack">
            <h3>武具を保護・整理する</h3>
            <form method="post" action="{{ route('nameless-workshop.act', 'protect-equipment') }}">
                @include('nameless-workshop.token')<input type="hidden" name="equipment_id" value="{{ $body->id }}"><input type="hidden" name="protected" value="{{ $body->is_locked ? 0 : 1 }}">
                <button class="secondary full">{{ $body->is_locked ? '武具の保護を解除する' : 'この武具を保護する' }}</button>
            </form>
            @if($body->acquisition_source === 'ruin' && !$body->is_locked && !$body->is_equipped && $body->relics->isEmpty())
                <details><summary>この武具を破棄する</summary>
                    <p class="error"><strong class="{{ $body->isRenamed() ? 'renamed-equipment' : '' }}">{{ $body->displayName() }} +{{ $body->forge_level }}（#{{ $body->id }}）</strong>と、成長EXP {{ number_format($body->growth_exp) }}を失います。育成に使った素材・遺物・Goldは戻りません。発見記録は残ります。</p>
                    <form method="post" action="{{ route('nameless-workshop.act', 'discard-equipment') }}" class="stack">
                        @include('nameless-workshop.token')<input type="hidden" name="equipment_id" value="{{ $body->id }}"><input type="hidden" name="revision" value="{{ $body->revision }}">
                        <label class="check"><input type="checkbox" name="confirmed" value="1" required>この個体と育成状態を失うことを確認しました</label><button class="danger full">武具の破棄を確定する</button>
                    </form>
                </details>
            @else<p class="muted">初期配布・保護中・装備中・遺物装着中の武具は破棄できません。自動処分はありません。</p>@endif
        </section>

        </details>
    </section>
@endif
@foreach(['weapon' => '武器'] as $kind => $label)
    @if(!in_array($kind, $starterKinds, true))
    <section class="card stack">
        <h2>名もなき{{ $label }}を受け取る</h2><p class="muted">最初の武器を1本受け取れます。形と名前を変更でき、育成状態は引き継ぎます。</p>
        <form method="post" action="{{ route('nameless-workshop.act', 'claim') }}" class="stack">
            @include('nameless-workshop.token')<input type="hidden" name="kind" value="{{ $kind }}">
            <label>形<select name="type">@foreach($types[$kind] as $type => $stat)<option value="{{ $type }}">{{ $type }}（{{ $stat['label'] }}）</option>@endforeach</select></label>
            <button class="full" @disabled($storageSummary['equipment_free'] === 0)>無料で受け取る</button>
        </form>
    </section>
    @endif
@endforeach

@if($tab === 'sets')
    <details class="card" @if($selectedRelicGrowth) open @endif><summary>所持遺物を育成・整理する（{{ $relics->count() }}個）</summary>@include('nameless-workshop.relics')</details>
@endif
<section class="card stack" data-nameless-collection>
    <div class="row between"><h2>武具の入手 {{ $collection['acquired_count'] }}／{{ $collection['total'] }}</h2><span class="badge">所持 {{ $equipment->count() }}個</span></div>
    <details><summary>入手した種類を見る</summary>
    <p class="muted">初期配布を含む所持中の種類と、遺跡で拾った記録を表示します。初期配布の形を変えると所持分の表示も変わります。遺跡の入手記録は強化・命名・破棄後も残ります。</p>
    <div class="collection-art">@foreach($collection['types'] as $type => $entry)<span class="badge {{ in_array($type, $collection['acquired'], true) ? 'gold' : '' }}" data-nameless-collection-type="{{ $type }}">@if($image = config('nameless_equipment_images.'.$entry['kind'].'.'.$type))<img src="{{ asset($image) }}" alt="" width="300" height="300" loading="lazy" decoding="async">@endif{{ in_array($type, $collection['acquired'], true) ? '入手済み' : '未入手' }} {{ $type }}</span>@endforeach</div>
    </details>
    <p class="muted">装備倉庫 {{ number_format($storageSummary['equipment_total']) }} / {{ number_format($storageSummary['equipment_limit']) }} · 通常装備と共通の枠を使います。</p>
    @if($storageSummary['equipment_full'])<p class="error">装備倉庫の所持枠がいっぱいです。倉庫や鍛冶屋で不要な武具を整理してください。</p>@endif
</section>
