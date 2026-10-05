@if($namelessRuins['unlocked_zone_name'] ?? null)<p class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-900" role="status">「{{ $namelessRuins['unlocked_zone_name'] }}」への道が開きました。探索タブから進めます。</p>@endif
<details open data-compact-accordion="relics" data-battle-compact-frame class="w-full rounded-lg border border-amber-200 bg-amber-50">
<summary class="hidden min-h-11 cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm font-black text-amber-900"><span>発見品：遺物 {{ count($namelessRuins['relic_drops'] ?? []) }}個 · 武具 {{ count($namelessRuins['nameless_equipment_drops'] ?? []) }}個</span><span class="text-[11px] text-slate-500">タップで表示 ⌄</span></summary>
<section class="p-3" data-nameless-relic-rewards>
    <p class="mb-2 text-xs font-bold text-slate-600">{{ $namelessRuins['zone_name'] }} · 深度{{ $namelessRuins['depth'] }}</p>
    <h2 class="text-sm font-black text-amber-900">遺物発見：{{ count($namelessRuins['relic_drops'] ?? []) }}個</h2>
    @foreach($namelessRuins['rare_encounters'] ?? [] as $encounter)
        <p class="mt-2 rounded bg-white px-2 py-1 text-xs font-bold text-amber-800">{{ $encounter['index'] }}回目：{{ $encounter['name'] }}{{ $encounter['kind'] === 'cleared_boss' ? '（撃破済みボスとの再遭遇）' : '（幸運な遭遇）' }} ／ {{ ['victory' => '勝利', 'defeat' => '敗北', 'timeout' => '時間切れ'][$encounter['result']] ?? $encounter['result'] }} · 遺物{{ $encounter['relic_count'] }}個</p>
    @endforeach
    @if(!empty($namelessRuins['relic_drops']))
        <div class="mt-2 flex flex-wrap gap-2" data-nameless-relic-drop-list>
            @foreach($namelessRuins['relic_drops'] as $relic)
                <span class="inline-flex max-w-full items-center gap-1.5 rounded border border-amber-200 bg-white px-3 py-2 text-sm font-bold text-slate-800 shadow-sm" data-nameless-relic-drop title="{{ $relic['summary'] }}"><x-relic-icon :effect-key="$relic['effect_key'] ?? null" :size="20" /><span class="min-w-0 break-words">{{ $relic['name'] }}</span></span>
            @endforeach
        </div>
        <details class="mt-2 text-xs text-slate-600">
            <summary class="inline-flex min-h-11 cursor-pointer items-center font-bold text-amber-900">遺物の効果を見る</summary>
            @foreach($namelessRuins['relic_drops'] as $relic)
                <div class="border-t border-amber-200 py-2"><strong>{{ $relic['name'] }}</strong><p class="mt-1">{{ $relic['summary'] }}</p></div>
            @endforeach
        </details>
    @else
        <p class="mt-2 text-xs text-slate-600">今回は遺物を見つけられませんでした。</p>
    @endif
    @if($namelessRuins['advanced'])<p class="mt-2 text-sm font-bold">深度{{ $namelessRuins['unlocked_depth'] }}を解放しました。</p>@endif
    @foreach($namelessRuins['nameless_equipment_drops'] ?? [] as $body)
        <div class="mt-3 rounded border border-blue-200 bg-blue-50 p-2 text-xs" data-nameless-equipment-drop>
            <strong>武具発見：［{{ \App\Models\PlayerNamelessEquipment::DISPLAY_RANK }}］ {{ $body['name'] }} +{{ $body['forge_level'] }}</strong>
            @if($body['new_discovery'])<span class="ml-1 font-bold text-blue-700">初めての発見！</span>@endif
            <p class="mt-1">名前を付けて、遺物と素材で育てられます。</p>
            <a class="mt-2 inline-flex min-h-11 items-center font-bold text-blue-800 underline" href="{{ route('nameless-workshop.index', ['equipment' => $body['id']]) }}">この武具を鍛冶屋で見る</a>
        </div>
    @endforeach
    <p class="mt-2 text-xs text-slate-500">遺跡の報酬は遺物と希少な名もなき武具です。EXP・職業EXP・Gold・通常ドロップは増えません。</p>
</section>
</details>
