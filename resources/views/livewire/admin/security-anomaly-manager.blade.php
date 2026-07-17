<div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8" wire:poll.60s>
    @php
        $statusLabels = ['detected' => '検知', 'reviewing' => '確認中', 'cleared' => '問題なし', 'actioned' => '措置済み'];
        $statusClasses = [
            'detected' => 'border-rose-200 bg-rose-50 text-rose-800',
            'reviewing' => 'border-amber-200 bg-amber-50 text-amber-900',
            'cleared' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'actioned' => 'border-slate-300 bg-slate-100 text-slate-800',
        ];
        $severityLabels = ['critical' => '重要', 'warning' => '要確認'];
        $formatEvidence = function ($value) {
            if (is_bool($value)) return $value ? 'はい' : 'いいえ';
            if (is_array($value)) return implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE), $value));
            if (is_int($value) || is_float($value)) return number_format($value);
            return $value === null || $value === '' ? '—' : (string) $value;
        };
    @endphp

    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">P1 SECURITY</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">異常検知・不正調査</h1>
            <p class="mt-2 max-w-3xl text-sm font-bold leading-relaxed text-slate-500">戦闘、通貨、Job EXP、ログイン元、所持品、市場取引をルールベースで監視します。検知だけを行い、自動的なアカウント停止や所持品回収はしません。</p>
        </div>
        <button type="button" wire:click="runScan" wire:loading.attr="disabled" @disabled(!$schemaReady) class="min-h-11 rounded-md bg-slate-950 px-5 text-sm font-black text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
            <span wire:loading.remove wire:target="runScan">今すぐ検知</span><span wire:loading wire:target="runScan">走査中…</span>
        </button>
    </div>

    @if ($lastScanMessage)
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-bold text-sky-900">{{ $lastScanMessage }}</div>
    @endif

    @if (!$schemaReady)
        <div class="rounded-lg border border-rose-300 bg-rose-50 p-5 text-sm font-bold leading-relaxed text-rose-900">異常検知用migrationが未適用です。<code class="rounded bg-white px-2 py-1">php artisan migrate</code> の適用後に監視を開始できます。</div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['detected' => '新規検知', 'reviewing' => '確認中', 'cleared' => '問題なし', 'actioned' => '措置済み'] as $status => $label)
                <button type="button" wire:click="$set('statusFilter', '{{ $status }}')" class="rounded-lg border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow {{ $statusFilter === $status ? 'border-amber-400 ring-2 ring-amber-200' : 'border-slate-200' }}">
                    <div class="text-xs font-black {{ $status === 'detected' ? 'text-rose-700' : ($status === 'reviewing' ? 'text-amber-700' : 'text-slate-600') }}">{{ $label }}</div>
                    <div class="mt-1 text-3xl font-black text-slate-950">{{ number_format((int) ($counts[$status] ?? 0)) }}<span class="ml-1 text-sm">件</span></div>
                </button>
            @endforeach
        </div>

        <details class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <summary class="cursor-pointer px-5 py-4 text-sm font-black text-slate-900">現在の検知基準</summary>
            <div class="grid gap-3 border-t border-slate-100 p-5 text-xs font-bold text-slate-600 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-md bg-slate-50 p-3">戦闘: {{ $thresholds['rapid_battles']['window_minutes'] }}分で{{ number_format($thresholds['rapid_battles']['threshold']) }}戦以上</div>
                <div class="rounded-md bg-slate-50 p-3">Gold: {{ $thresholds['gold_change']['window_minutes'] }}分で合計{{ number_format($thresholds['gold_change']['total_threshold']) }} / 単一{{ number_format($thresholds['gold_change']['single_threshold']) }}以上</div>
                <div class="rounded-md bg-slate-50 p-3">輝石: {{ $thresholds['kiseki_change']['window_minutes'] }}分で合計{{ number_format($thresholds['kiseki_change']['total_threshold']) }} / 単一{{ number_format($thresholds['kiseki_change']['single_threshold']) }}以上</div>
                <div class="rounded-md bg-slate-50 p-3">Job EXP: 1報酬{{ $thresholds['job_exp']['max_per_reward'] }}超</div>
                <div class="rounded-md bg-slate-50 p-3">同一IP: {{ $thresholds['shared_ip']['window_days'] }}日で{{ $thresholds['shared_ip']['account_threshold'] }}アカウント以上</div>
                <div class="rounded-md bg-slate-50 p-3">所持品: 前回走査から装備+{{ $thresholds['inventory_growth']['equipment_threshold'] }} / 素材+{{ number_format($thresholds['inventory_growth']['material_threshold']) }}以上</div>
                <div class="rounded-md bg-slate-50 p-3">付与後取引: {{ $thresholds['admin_grant_trade']['window_hours'] }}時間以内に{{ number_format($thresholds['admin_grant_trade']['price_threshold']) }} Gold以上</div>
            </div>
        </details>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(360px,0.9fr)]">
            <section class="min-w-0 space-y-4">
                <div class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3">
                    <label class="text-xs font-black text-slate-600">状態
                        <select wire:model.live="statusFilter" class="mt-2 min-h-11 w-full rounded-md border-slate-300 text-sm font-bold">
                            <option value="active">対応中のみ</option><option value="">すべて</option>
                            @foreach ($statusLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs font-black text-slate-600">ルール
                        <select wire:model.live="ruleFilter" class="mt-2 min-h-11 w-full rounded-md border-slate-300 text-sm font-bold">
                            <option value="">すべて</option>
                            @foreach ($ruleLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-xs font-black text-slate-600">検索
                        <input wire:model.live.debounce.400ms="search" type="search" placeholder="冒険者名・ID・概要" class="mt-2 min-h-11 w-full rounded-md border-slate-300 text-sm font-bold">
                    </label>
                </div>

                @if ($cases->isEmpty())
                    <div class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-sm font-bold text-slate-500">条件に一致する検知案件はありません。</div>
                @else
                    <div class="space-y-3">
                        @foreach ($cases as $case)
                            <button type="button" wire:click="selectCase({{ $case->id }})" class="block w-full rounded-lg border bg-white p-4 text-left shadow-sm transition hover:border-amber-300 hover:shadow {{ $selectedCaseId === $case->id ? 'border-amber-400 ring-2 ring-amber-200' : 'border-slate-200' }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full border px-2 py-1 text-[11px] font-black {{ $statusClasses[$case->status] ?? $statusClasses['detected'] }}">{{ $statusLabels[$case->status] ?? $case->status }}</span>
                                            <span class="rounded-full {{ $case->severity === 'critical' ? 'bg-rose-600 text-white' : 'bg-amber-100 text-amber-900' }} px-2 py-1 text-[11px] font-black">{{ $severityLabels[$case->severity] ?? $case->severity }}</span>
                                            <span class="text-xs font-black text-slate-500">{{ $ruleLabels[$case->rule_key] ?? $case->rule_key }}</span>
                                        </div>
                                        <h2 class="mt-2 text-base font-black text-slate-950">{{ $case->title }}</h2>
                                    </div>
                                    <time class="shrink-0 text-xs font-bold text-slate-500">{{ $case->last_detected_at?->format('m/d H:i:s') }}</time>
                                </div>
                                <p class="mt-2 text-sm font-bold leading-relaxed text-slate-700">{{ $case->summary }}</p>
                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-bold text-slate-500">
                                    <span>{{ $case->character?->name ?? ($case->user_id ? 'ユーザーID '.$case->user_id : 'IPグループ') }}</span>
                                    @if ($case->character_id)<span>キャラID {{ $case->character_id }}</span>@endif
                                    <span>検知 {{ $case->detection_count }}回</span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                    <div>{{ $cases->links() }}</div>
                @endif
            </section>

            <aside class="min-w-0">
                <div class="sticky top-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    @if (!$selectedCase)
                        <div class="p-8 text-center text-sm font-bold text-slate-500">左の案件を選ぶと、証拠と状態変更を表示します。</div>
                    @else
                        <div class="border-b border-slate-200 bg-slate-950 p-5 text-white">
                            <div class="flex flex-wrap items-center gap-2"><span class="rounded-full border border-white/20 bg-white/10 px-2 py-1 text-xs font-black">#{{ $selectedCase->id }}</span><span class="text-xs font-black text-amber-300">{{ $ruleLabels[$selectedCase->rule_key] ?? $selectedCase->rule_key }}</span></div>
                            <h2 class="mt-3 text-xl font-black">{{ $selectedCase->title }}</h2>
                            <p class="mt-2 text-sm font-bold leading-relaxed text-slate-300">{{ $selectedCase->summary }}</p>
                        </div>

                        <div class="space-y-5 p-5">
                            <div class="flex flex-wrap gap-2">
                                @if ($selectedCase->user_id)
                                    <a href="{{ route('admin.user-investigation', ['user_id' => $selectedCase->user_id]) }}" class="rounded-md bg-amber-400 px-3 py-2 text-xs font-black text-slate-950 hover:bg-amber-300">ユーザー調査を開く</a>
                                @endif
                                <a href="{{ route('admin.action-logs') }}" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-black text-slate-800 hover:bg-slate-50">行動ログを開く</a>
                            </div>

                            @if (($selectedCase->evidence ?? []) !== [])
                                <section><h3 class="text-sm font-black text-slate-950">検知証拠</h3><dl class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
                                    @foreach ($selectedCase->evidence as $key => $value)
                                        <div class="grid grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] gap-3 px-3 py-2 text-xs"><dt class="break-words font-black text-slate-500">{{ $key }}</dt><dd class="break-words text-right font-bold text-slate-900">{{ $formatEvidence($value) }}</dd></div>
                                    @endforeach
                                </dl></section>
                            @endif

                            <section>
                                <label class="text-sm font-black text-slate-950">調査メモ・判断理由
                                    <textarea wire:model="resolutionNote" rows="4" class="mt-2 w-full rounded-md border-slate-300 text-sm" placeholder="確認したログ、問題なしの根拠、実施した措置を記録"></textarea>
                                </label>
                                @error('resolutionNote')<p class="mt-1 text-xs font-bold text-rose-700">{{ $message }}</p>@enderror
                                <button type="button" wire:click="saveNote" class="mt-3 min-h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-xs font-black text-slate-800 hover:bg-slate-50">状態を変えずにメモを保存</button>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    @foreach ($statusLabels as $key => $label)
                                        <button type="button" wire:click="updateStatus('{{ $key }}')" class="min-h-10 rounded-md border text-xs font-black transition {{ $selectedCase->status === $key ? $statusClasses[$key] : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                            </section>

                            <section><h3 class="text-sm font-black text-slate-950">状態変更履歴</h3>
                                @if ($selectedCase->events->isEmpty())<p class="mt-2 text-xs font-bold text-slate-500">まだ状態変更はありません。</p>
                                @else<div class="mt-2 space-y-2">@foreach ($selectedCase->events as $event)<div class="rounded-md bg-slate-50 p-3 text-xs"><div class="font-black text-slate-800">{{ $statusLabels[$event->from_status] ?? $event->from_status }} → {{ $statusLabels[$event->to_status] ?? $event->to_status }}</div><div class="mt-1 text-slate-500">{{ $event->adminUser?->name ?? '削除済み管理者' }} / {{ $event->created_at?->format('Y-m-d H:i:s') }}</div>@if ($event->note)<p class="mt-2 whitespace-pre-wrap font-bold text-slate-700">{{ $event->note }}</p>@endif</div>@endforeach</div>@endif
                            </section>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    @endif
</div>
