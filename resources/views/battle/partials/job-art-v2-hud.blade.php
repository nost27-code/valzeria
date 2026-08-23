@php
    $jobArtV2Hud = $jobArtV2Hud ?? null;
    $hudActors = is_array($jobArtV2Hud) ? ($jobArtV2Hud['actors'] ?? []) : [];
    $hudActions = is_array($jobArtV2Hud) ? ($jobArtV2Hud['actions'] ?? []) : [];
    $hudRoundEvents = is_array($jobArtV2Hud) ? ($jobArtV2Hud['round_events'] ?? []) : [];
    $fieldSummary = static function (?array $field): string {
        if ($field === null) {
            return 'なし';
        }
        $parts = [(string) ($field['name'] ?? '場'), '残り'.number_format((int) ($field['remaining_rounds'] ?? 0)), '所有：'.(string) ($field['owner_label'] ?? '不明')];
        if ((int) ($field['lock_remaining_rounds'] ?? 0) > 0) {
            $parts[] = '固定 残り'.number_format((int) $field['lock_remaining_rounds']);
        }

        return implode(' / ', $parts);
    };
@endphp

@if(is_array($jobArtV2Hud) && $hudActors !== [])
    <section class="mb-5 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50/60 shadow-sm" data-job-art-v2-hud>
        <div class="border-b border-indigo-200 bg-indigo-950 px-4 py-3 text-white">
            <div class="text-sm font-black tracking-wide">戦技の流れ</div>
            <p class="mt-1 text-[11px] font-bold leading-relaxed text-indigo-100">戦闘終了時の状態です。詳しい変化は下の「戦技の変化を見る」で確認できます。</p>
        </div>

        <div class="space-y-3 p-3 sm:p-4">
            @foreach($hudActors as $actor)
                @php
                    $resource = $actor['resource'] ?? [];
                    $resources = is_array($actor['resources'] ?? null) && $actor['resources'] !== []
                        ? $actor['resources']
                        : [$resource];
                    $stance = $actor['stance'] ?? null;
                    $guard = $actor['guard'] ?? null;
                    $debuff = $actor['debuff'] ?? null;
                @endphp
                <article class="rounded-lg border border-indigo-100 bg-white p-3" data-hud-actor="{{ $actor['actor_key'] ?? '' }}">
                    <div class="flex min-w-0 items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-[10px] font-black text-indigo-500">{{ $actor['actor_label'] ?? '' }}</div>
                            <div class="truncate text-sm font-black text-slate-900">{{ $actor['actor_name'] ?? '' }}</div>
                        </div>
                        <div class="flex flex-wrap justify-end gap-1">
                            @foreach($resources as $resourceSummary)
                                <span class="shrink-0 rounded-full bg-indigo-100 px-2 py-1 text-[11px] font-black text-indigo-800">{{ $resourceSummary['name'] ?? 'リソース' }} {{ (int) ($resourceSummary['points'] ?? 0) }}/{{ max(1, (int) ($resourceSummary['cap'] ?? 12)) }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-2 space-y-2">
                        @foreach($resources as $resourceSummary)
                            @php
                                $resourcePoints = (int) ($resourceSummary['points'] ?? 0);
                                $resourceCap = max(1, (int) ($resourceSummary['cap'] ?? 12));
                                $resourceFull = !empty($resourceSummary['is_full']);
                            @endphp
                            <div>
                                <div class="flex items-center justify-between gap-2 text-[10px] font-black text-slate-600">
                                    <span>{{ !empty($resourceSummary['is_primary']) ? '現在職' : '継承' }}・{{ $resourceSummary['name'] ?? 'リソース' }}</span>
                                    <span class="{{ $resourceFull ? 'text-amber-700' : '' }}">{{ $resourceFull ? '奥義を使用可能' : '奥義まであと'.number_format((int) ($resourceSummary['remaining'] ?? 0)) }}</span>
                                </div>
                                <div class="mt-1 h-2.5 overflow-hidden rounded-full border border-indigo-100 bg-slate-100" aria-label="{{ $resourceSummary['name'] ?? 'リソース' }} {{ $resourcePoints }}/{{ $resourceCap }}">
                                    <div class="h-full rounded-full bg-gradient-to-r from-sky-500 to-indigo-600" style="width: {{ (int) ($resourceSummary['percent'] ?? 0) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <dl class="mt-3 space-y-1.5 text-[11px] font-bold text-slate-700">
                        <div class="flex min-w-0 items-start gap-2"><dt class="w-14 shrink-0 text-slate-500">主場</dt><dd class="min-w-0 break-words">{{ $fieldSummary($actor['field'] ?? null) }}</dd></div>
                        @if(!empty($actor['overlay']))
                            <div class="flex min-w-0 items-start gap-2"><dt class="w-14 shrink-0 text-slate-500">副場</dt><dd class="min-w-0 break-words">{{ $fieldSummary($actor['overlay']) }}</dd></div>
                        @endif
                        @if(!empty($actor['echo']))
                            <div class="flex min-w-0 items-start gap-2"><dt class="w-14 shrink-0 text-slate-500">残響</dt><dd class="min-w-0 break-words">{{ $fieldSummary($actor['echo']) }}</dd></div>
                        @endif
                        @if(($actor['field_overwrite_count'] ?? null) !== null)
                            <div class="flex min-w-0 items-start gap-2"><dt class="w-14 shrink-0 text-slate-500">転輪</dt><dd class="min-w-0 break-words">場の上書き {{ number_format((int) $actor['field_overwrite_count']) }}回</dd></div>
                        @endif
                        @if(is_array($stance))
                            <div class="flex min-w-0 items-center gap-2">
                                <dt class="w-14 shrink-0 text-slate-500">構え</dt>
                                <dd><span class="rounded px-2 py-0.5 text-[10px] font-black {{ !empty($stance['active']) ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-100 text-slate-500' }}">{{ $stance['name'] ?? '構え' }} {{ !empty($stance['active']) ? 'ON' : 'OFF' }}@if(!empty($stance['active']) && isset($stance['remaining_rounds'])) ・残り{{ (int) $stance['remaining_rounds'] }}R / 受け流し{{ (int) ($stance['rate_percent'] ?? 0) }}%@endif</span></dd>
                            </div>
                        @endif
                        @if(is_array($guard))
                            <div class="flex min-w-0 items-center gap-2">
                                <dt class="w-14 shrink-0 text-slate-500">加護</dt>
                                <dd><span class="rounded px-2 py-0.5 text-[10px] font-black {{ !empty($guard['active']) ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-500' }}">次の直接ダメージ軽減 {{ !empty($guard['active']) ? ((int) ($guard['rate_percent'] ?? 0)).'% / 1回' : 'なし' }}</span></dd>
                            </div>
                        @endif
                        @if(is_array($debuff))
                            <div class="flex min-w-0 items-center gap-2">
                                <dt class="w-14 shrink-0 text-slate-500">状態</dt>
                                <dd><span class="rounded bg-violet-100 px-2 py-0.5 text-[10px] font-black text-violet-800">{{ $debuff['name'] ?? '崩し' }} 防御/精神 -{{ rtrim(rtrim(number_format((float) ($debuff['rate_percent'] ?? 0), 1), '0'), '.') }}%・残り{{ (int) ($debuff['remaining_rounds'] ?? 0) }}R</span></dd>
                            </div>
                        @endif
                        @foreach(($actor['progression'] ?? []) as $progressionLabel)
                            <div class="flex min-w-0 items-start gap-2">
                                <dt class="w-14 shrink-0 text-slate-500">系譜</dt>
                                <dd class="min-w-0 break-words"><span class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-black text-amber-900">{{ $progressionLabel }}</span></dd>
                            </div>
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </div>

        @if($hudActions !== [] || $hudRoundEvents !== [])
            <details class="border-t border-indigo-200 bg-white" data-job-art-v2-hud-details>
                <summary class="cursor-pointer list-none px-4 py-3 text-xs font-black text-indigo-800 marker:hidden">戦技の変化を見る <span class="text-[10px] font-bold text-slate-500">（戦闘終了後の記録）</span></summary>
                <div class="space-y-2 border-t border-indigo-100 p-3 sm:p-4">
                    @foreach($hudActions as $action)
                        <article class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs" data-hud-action="{{ $action['source_action_id'] ?? '' }}">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-black text-white">T{{ $action['turn'] ?? 0 }}</span>
                                <span class="text-[10px] font-black text-slate-500">{{ $action['actor_label'] ?? '' }}</span>
                                @if(!empty($action['rank_label']))<span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-black text-indigo-800">{{ $action['rank_label'] }}</span>@endif
                                <strong class="min-w-0 break-words text-slate-900">{{ $action['action_name'] ?? '戦技' }}</strong>
                                @if(($action['hit_result'] ?? null) === 'HIT')
                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-black text-emerald-800">HIT</span>
                                @elseif(($action['hit_result'] ?? null) === 'MISS')
                                    <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-black text-rose-800">MISS</span>
                                @elseif(($action['hit_result'] ?? null) === 'EVADE')
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-black text-amber-800">EVADE：回避された</span>
                                @endif
                                @if($action['penetration_percent'] !== null)<span class="rounded bg-cyan-100 px-1.5 py-0.5 text-[10px] font-black text-cyan-800">防御貫通 {{ $action['penetration_percent'] }}%</span>@endif
                                @if(is_array($action['field_overwrite_power'] ?? null) && (int) ($action['field_overwrite_power']['bonus_percent'] ?? 0) > 0)
                                    <span class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-black text-violet-800">上書き {{ (int) $action['field_overwrite_power']['overwrite_count'] }}回・星冠転輪 +{{ (int) $action['field_overwrite_power']['bonus_percent'] }}%</span>
                                @endif
                            </div>
                            @if(!empty($action['changes']))
                                <ul class="mt-2 space-y-1 text-[11px] font-bold leading-relaxed text-slate-700">
                                    @foreach($action['changes'] as $change)
                                        @if(($change['type'] ?? '') === 'resource')
                                            <li>・{{ $change['name'] }} {{ $change['before'] }}→{{ $change['after'] }}/{{ $change['cap'] }}（{{ $change['delta_label'] }}）{{ !empty($change['is_full']) ? ' / 奥義ゲージ満タン' : '' }}</li>
                                        @elseif(($change['type'] ?? '') === 'sp')
                                            <li>・SP {{ number_format((int) $change['before']) }}→{{ number_format((int) $change['after']) }}</li>
                                        @elseif(($change['type'] ?? '') === 'target_sp')
                                            <li>・対象SP {{ number_format((int) $change['before']) }}→{{ number_format((int) $change['after']) }}（-{{ number_format((int) $change['actual_loss']) }}）</li>
                                        @elseif(($change['type'] ?? '') === 'conversion')
                                            <li>・変成 HP {{ number_format((int) $change['hp_before']) }}→{{ number_format((int) $change['hp_after']) }}（-{{ number_format((int) $change['actual_hp_loss']) }}） / SP {{ number_format((int) $change['sp_before']) }}→{{ number_format((int) $change['sp_after']) }}（+{{ number_format((int) $change['actual_sp_gain']) }}）</li>
                                        @elseif(($change['type'] ?? '') === 'break_debuff')
                                            @php
                                                $breakEventLabels = [
                                                    'applied' => '崩しを付与',
                                                    'replaced' => 'より強い崩しへ置換',
                                                    'refreshed' => '崩しの持続を更新',
                                                    'kept_stronger' => '既存の強い崩しを維持',
                                                ];
                                            @endphp
                                            <li>・{{ $breakEventLabels[$change['event'] ?? ''] ?? '崩し' }}：防御/精神 -{{ rtrim(rtrim(number_format((float) ($change['rate_percent'] ?? 0), 1), '0'), '.') }}%（残り{{ (int) ($change['remaining_rounds'] ?? 0) }}R）</li>
                                        @elseif(($change['type'] ?? '') === 'main_field')
                                            <li>・主場 {{ $fieldSummary($change['before'] ?? null) }} → {{ $fieldSummary($change['after'] ?? null) }}</li>
                                        @elseif(($change['type'] ?? '') === 'overlay')
                                            <li>・副場 {{ $fieldSummary($change['before'] ?? null) }} → {{ $fieldSummary($change['after'] ?? null) }}</li>
                                        @elseif(($change['type'] ?? '') === 'field_echo')
                                            <li>・残響 {{ $fieldSummary($change['before'] ?? null) }} → {{ $fieldSummary($change['after'] ?? null) }}</li>
                                        @elseif(($change['type'] ?? '') === 'field_overwrite_count')
                                            <li>・場の上書き回数 {{ (int) $change['before'] }}→{{ (int) $change['after'] }}</li>
                                        @elseif(($change['type'] ?? '') === 'stance')
                                            <li>・貫通構え {{ !empty($change['before']) ? 'ON' : 'OFF' }}→{{ !empty($change['after']) ? 'ON' : 'OFF' }}</li>
                                        @elseif(($change['type'] ?? '') === 'stance_event')
                                            <li>・{{ $change['label'] }}</li>
                                        @elseif(($change['type'] ?? '') === 'counter_stance')
                                            @php
                                                $counterLabels = ['applied' => '剣冠の構えを付与', 'refreshed' => '剣冠の構えを更新'];
                                            @endphp
                                            <li>・{{ $counterLabels[$change['event'] ?? ''] ?? '剣冠の構え' }}（残り{{ (int) ($change['remaining_rounds'] ?? 0) }}R）</li>
                                        @elseif(($change['type'] ?? '') === 'counter_stance_state')
                                            <li>・剣冠の構え {{ !empty($change['before']['active']) ? 'ON' : 'OFF' }}→{{ !empty($change['after']['active']) ? 'ON' : 'OFF' }}@if(!empty($change['after']['active']))（残り{{ (int) ($change['after']['remaining_rounds'] ?? 0) }}R）@endif</li>
                                        @elseif(($change['type'] ?? '') === 'parry')
                                            <li>・受け流し {{ !empty($change['success']) ? '成功' : '不成立' }}（{{ (int) ($change['rate_percent'] ?? 0) }}%）：ダメージ {{ number_format((int) ($change['damage_before'] ?? 0)) }}→{{ number_format((int) ($change['damage_after'] ?? 0)) }}@if((int) ($change['counter_damage'] ?? 0) > 0)／王冠剣陣の反撃 {{ number_format((int) $change['counter_damage']) }}@endif</li>
                                        @elseif(($change['type'] ?? '') === 'guard_state')
                                            <li>・加護 {{ !empty($change['before']['active']) ? ((int) ($change['before']['rate_percent'] ?? 0)).'%' : 'なし' }}→{{ !empty($change['after']['active']) ? ((int) ($change['after']['rate_percent'] ?? 0)).'%' : 'なし' }}</li>
                                        @elseif(($change['type'] ?? '') === 'active_guard')
                                            <li>・加護{{ (int) ($change['rate_percent'] ?? 0) }}%を消費：ダメージ {{ number_format((int) ($change['damage_before'] ?? 0)) }}→{{ number_format((int) ($change['damage_after'] ?? 0)) }}（{{ number_format((int) ($change['prevented_damage'] ?? 0)) }}軽減）</li>
                                        @elseif(($change['type'] ?? '') === 'cleanse')
                                            <li>・浄化 {{ !empty($change['success']) ? implode(' / ', (array) ($change['removed_states'] ?? [])) : '対象なし' }}（解除{{ (int) ($change['removed_count'] ?? 0) }}件）</li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    @endforeach
                    @foreach($hudRoundEvents as $event)
                        <div class="rounded border border-slate-200 bg-white px-3 py-2 text-[11px] font-bold text-slate-600">T{{ $event['turn'] ?? 0 }} ・ {{ $event['label'] ?? '' }}</div>
                    @endforeach
                </div>
            </details>
        @endif
    </section>
@endif
