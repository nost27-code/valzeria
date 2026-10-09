@php($cost = $forge->get($body->id))
<section id="forge" class="forge-panel stack" data-nameless-forge>
    @if($body->forge_level >= 99)
        <p>最大まで強化しました。遺物を組み合わせて仕上げましょう。</p>
    @elseif($cost['next_level'] > $cost['cap'])
        <p class="muted">遺跡のボスを倒し、さらに深く進むと次の強化が解放されます。</p>
    @else
    <form method="post" action="{{ route('nameless-workshop.act', 'preview-forge') }}" class="stack" x-data="{
        quantities: @js($forgeSelection['quantities']), units: @js($forgeSelection['units']),
        relicUnits: @js($forgeSelection['relicUnits']), relicIds: @js($forgeSelection['relicIds']),
        bestIds: @js($forgeSelection['bestIds']), protectBest: @js($forgeSelection['protectBest']),
        required: @js($forgeSelection['required']), target: @js($cost['exp']), saved: @js($body->growth_exp), search: '',
        canPay: @js($cost['payment']['can_pay']), needsBank: @js($cost['payment']['requires_bank']), useBank: @js($forgeSelection['useBank']),
        get total() { return Object.entries(this.quantities).reduce((sum, [id, quantity]) => sum + Math.max(0, Number(quantity) || 0) * this.units[id], 0) + this.relicIds.reduce((sum, id) => sum + this.relicUnits[id], 0); },
        get points() { return this.saved + this.total; },
        get percent() { return Math.min(100, Math.max(0, this.points / this.target * 100)); },
        get ready() { return this.points >= this.target && this.canPay && (!this.needsBank || this.useBank); },
        step(id, delta, owned) { this.quantities[id] = Math.min(owned, Math.max(0, Math.trunc(Number(this.quantities[id]) || 0) + delta)); },
        protectHighest() { if (this.protectBest) this.relicIds = this.relicIds.filter(id => !this.bestIds.includes(String(id))); },
        relicChoices: @js($forgeRelics->values()),
        selectRelics(lowOnly = false) { const ids = this.relicChoices.filter(relic => (!this.search || relic.name.includes(this.search)) && (!lowOnly || relic.rank <= 3) && (!this.protectBest || !this.bestIds.includes(String(relic.id)))).map(relic => String(relic.id)); this.relicIds = [...new Set([...this.relicIds, ...ids])].slice(0, 300); },
        fill(id, owned) { this.quantities[id] = Math.min(owned, (Number(this.quantities[id]) || 0) + Math.ceil(Math.max(0, this.required - this.total) / this.units[id])); }
    }" x-init="protectHighest()">
        @include('nameless-workshop.token')
        <input type="hidden" name="equipment_id" value="{{ $body->id }}"><input type="hidden" name="revision" value="{{ $body->revision }}">
        <div class="forge-upgrade" data-nameless-forge-gain><span>+{{ $body->forge_level }} → +{{ $cost['next_level'] }}</span><strong>{{ $body->kindLabel().'性能 ' }}{{ $body->performanceLabel($cost['next_level']) }}</strong></div>
        <div class="forge-price"><span>強化費用</span><strong>{{ number_format($cost['gold']) }} <small>G</small></strong><small>手持ち {{ number_format($character->money) }} G</small></div>
        @if($cost['payment']['requires_bank'] && $cost['payment']['can_pay'])
            <label class="check"><input type="checkbox" name="use_bank" value="1" x-model="useBank" @checked($forgeSelection['useBank'])>預金を使う（手持ち {{ number_format($cost['payment']['hand_gold_used']) }} G ＋ 預金 {{ number_format($cost['payment']['bank_gold_used']) }} G）</label>
        @endif
        <div class="forge-progress" :class="{ 'complete': points >= target }" data-nameless-forge-progress>
            <div class="row between"><strong>強化ポイント</strong><span><b x-text="points.toLocaleString()">{{ number_format($body->growth_exp) }}</b> / {{ number_format($cost['exp']) }} <small>pt</small></span></div>
            <div class="forge-progress-track" role="progressbar" aria-label="次の強化に必要なポイント" aria-valuemin="0" aria-valuemax="{{ $cost['exp'] }}" aria-valuenow="{{ min($body->growth_exp, $cost['exp']) }}" :aria-valuenow="Math.min(points, target)" :aria-valuetext="points.toLocaleString() + ' / ' + target.toLocaleString() + ' ポイント'"><span class="forge-progress-fill" :style="{ width: percent + '%' }"></span></div>
            <div class="forge-progress-breakdown"><span>持ち越し {{ number_format($body->growth_exp) }} pt</span><span>＋ 選択 <b x-text="total.toLocaleString()">0</b> pt</span></div>
            <p class="forge-progress-message" role="status" aria-live="polite" x-text="points < target ? 'あと ' + (target - points).toLocaleString() + ' ptで強化できます。' : '必要ポイントが溜まりました。' + (points > target ? '余り ' + (points - target).toLocaleString() + ' ptは次へ持ち越します。' : '')">必要な素材を選んでください。</p>
        </div>
        <h3>使う素材を選ぶ</h3>
        <p class="muted">素材・遺物を選ぶとバーが増えます。通常素材1個分＝1 pt。選ぶだけでは消費されません。</p>
        @if($forgeSelection['required'] === 0)<p class="muted">持ち越し分で強化できます。追加の素材は不要です。</p>@endif
        <input type="search" class="full" aria-label="強化素材・遺物を探す" placeholder="素材・遺物の名前で探す" x-model="search">
        <details open class="forge-sources"><summary>素材（{{ $forgeMaterials->count() }}種類）</summary>
            <div class="material-choices">
            @forelse($forgeMaterials as $material)
                <div class="forge-material" :class="{ 'chosen': Number(quantities[{{ $material['id'] }}]) > 0 }" x-show="!search || $el.textContent.includes(search)">
                    <label for="forge-material-{{ $material['id'] }}" class="forge-source-copy">
                        @if($material['icon'])<img src="{{ asset($material['icon']) }}" alt="" width="160" height="160" loading="lazy">@endif
                        <span><strong>{{ $material['name'] }}</strong><small>所持 {{ number_format($material['owned']) }} 個 · 1個＝{{ $material['unit'] }} pt</small></span>
                    </label>
                    <div class="forge-quantity">
                        <div class="forge-stepper">
                            <button type="button" class="secondary quantity-step" aria-label="{{ $material['name'] }}を1個減らす" @click="step({{ $material['id'] }}, -1, {{ $material['owned'] }})" :disabled="Number(quantities[{{ $material['id'] }}]) <= 0">−</button>
                            <input id="forge-material-{{ $material['id'] }}" type="number" inputmode="numeric" name="materials[{{ $material['id'] }}]" min="0" max="{{ $material['owned'] }}" step="1" value="{{ $forgeSelection['quantities'][$material['id']] }}" x-model.number="quantities[{{ $material['id'] }}]" @blur="if (quantities[{{ $material['id'] }}] === '') quantities[{{ $material['id'] }}] = 0" aria-label="{{ $material['name'] }}の使用個数">
                            <button type="button" class="secondary quantity-step" aria-label="{{ $material['name'] }}を1個増やす" @click="step({{ $material['id'] }}, 1, {{ $material['owned'] }})" :disabled="Number(quantities[{{ $material['id'] }}]) >= {{ $material['owned'] }}">＋</button><span>個</span>
                        </div>
                        <button type="button" class="secondary fill-material" @click="fill({{ $material['id'] }}, {{ $material['owned'] }})">不足分を入れる</button>
                    </div>
                </div>
            @empty<p class="muted">強化に使える素材がありません。大陸の探索で集めましょう。</p>@endforelse
            </div>
        </details>
        <details class="forge-sources"><summary>遺物（{{ $forgeRelics->count() }}個）</summary>
            <p class="muted">I〜IIIは3 pt、IV〜VIは4 pt、VII〜IXは5 pt。<strong>使った遺物は失われ、効果は武具に残りません。</strong></p>
            <input type="hidden" name="protect_best" value="0">
            <label class="check"><input type="checkbox" name="protect_best" value="1" x-model="protectBest" @change="protectHighest()" @checked($forgeSelection['protectBest'])>各効果の最高ランクを1つ残す</label>
            <div class="row bulk-actions" data-relic-bulk-select>
                <button type="button" class="secondary" @click="selectRelics()">表示中を一括選択</button>
                <button type="button" class="secondary" @click="selectRelics(true)">表示中のI〜IIIを選択</button>
                <button type="button" class="secondary" @click="relicIds = []">選択をすべて解除</button>
            </div>
            <small>遺物素材は一度に300個まで選べます。</small>
            <p class="muted" role="status" x-text="'選択中 ' + relicIds.length + ' 個'">選択中 {{ count($forgeSelection['relicIds']) }} 個</p>
            <div class="material-choices">
            @forelse($forgeRelics as $relic)
                <div class="forge-material" :class="{ 'chosen': relicIds.includes('{{ $relic['id'] }}') }" x-show="!search || @js($relic['name']).includes(search)">
                    <label class="check forge-relic-choice"><input type="checkbox" name="relics[]" value="{{ $relic['id'] }}" x-model="relicIds" :disabled="(protectBest && bestIds.includes('{{ $relic['id'] }}')) || (relicIds.length >= 300 && !relicIds.includes('{{ $relic['id'] }}'))" @checked(in_array((string) $relic['id'], $forgeSelection['relicIds'], true))><x-relic-icon :effect-key="$relic['effect_key']" /><span class="relic-copy"><strong>{{ $relic['name'] }}</strong><small>{{ $relic['unit'] }} pt @if(in_array($relic['id'], $bestIds, true)) · 最高ランク@endif</small></span></label>
                    <details class="forge-effect"><summary>効果を見る</summary><p>{{ $relic['summary'] }}</p><small>{{ $relic['description'] }}</small></details>
                </div>
            @empty<p class="muted">強化に使える遺物がありません。保護中・装着中の遺物は表示されません。</p>@endforelse
            </div>
        </details>
        @if(!$cost['payment']['can_pay'])<p class="error">手持ちと預金を合わせてもゴールドが不足しています。</p>@endif
        <button class="full" data-nameless-forge-submit @disabled(!$cost['payment']['can_pay']) :disabled="!ready">{{ number_format($cost['gold']) }} Gで +{{ $cost['next_level'] }} に強化する</button>
        <small class="muted">次の画面で消費内容を確認します。素材・遺物とゴールドは強化確定時に消費します。</small>
    </form>
    @endif
</section>
