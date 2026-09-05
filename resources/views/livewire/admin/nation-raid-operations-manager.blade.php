<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 text-slate-800 sm:px-6" data-nation-raid-operations>
    <header>
        <h1 class="text-2xl font-black">国家対抗レイド 開催管理</h1>
        <p class="mt-2 text-sm text-slate-600">新規出撃の停止と、確保済み出撃の精算・返却は別に扱います。表示だけではイベントを作成・開始しません。</p>
        <p class="mt-2 text-sm font-bold text-amber-800">終了確定は最終順位・個人の報酬権利・国家報酬を保存します。未回収出撃・未確定の系譜がある場合は停止します。承認の追加・公開設定の変更はできません。</p>
        <a href="{{ route('admin.nation-raid-analytics') }}" class="mt-3 inline-block text-sm text-blue-700 underline">戦闘の分析・品質を確認する</a>
    </header>

    @if(session('status'))
        <p role="status" class="border-l-4 border-emerald-500 bg-white p-3 text-sm">{{ session('status') }}</p>
    @endif
    @if(session('error'))
        <p role="alert" class="border-l-4 border-red-500 bg-white p-3 text-sm">{{ session('error') }}</p>
    @endif
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="font-bold">開始に必要な設定</h2>
        <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
            @foreach($flags as [$label, $actual, $expected])
                <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                    <dt>{{ $label }}</dt>
                    <dd class="shrink-0 {{ $actual === $expected ? 'text-slate-600' : 'font-bold text-amber-800' }}">{{ $actual ? 'ON' : 'OFF' }}（必要: {{ $expected ? 'ON' : 'OFF' }}）</dd>
                </div>
            @endforeach
        </dl>
    </section>

    @if(!$schemaReady)
        <p role="alert">イベント用のDBが未整備です。必要なmigrationを確認してください。</p>
    @else
        <details class="rounded-lg border border-slate-200 bg-white p-4">
            <summary class="cursor-pointer font-bold">開催の下書き</summary>
            <p class="mt-3 text-sm text-slate-600">候補ルールを保存するだけです。バランス裁定の記録後に、別操作で開催予約します。期間は{{ (int) config('nation_raid.event.duration_hours') }}時間、予告は開始{{ (int) config('nation_raid.event.announcement_lead_hours') }}時間前までに必要です。</p>
            <form wire:submit="createDraft" class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="text-sm">管理用キー
                    <input wire:model="eventKey" type="text" maxlength="64" placeholder="valgreid-2026-09" class="mt-1 block w-full rounded border-slate-300" required>
                    @error('eventKey')<span class="text-red-700">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">イベント名
                    <input wire:model="eventName" type="text" maxlength="80" class="mt-1 block w-full rounded border-slate-300" required>
                    @error('eventName')<span class="text-red-700">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">開始日時（{{ config('app.timezone') }}）
                    <input wire:model="startsAt" type="datetime-local" class="mt-1 block w-full rounded border-slate-300" required>
                    @error('startsAt')<span class="text-red-700">{{ $message }}</span>@enderror
                </label>
                <button type="submit" wire:loading.attr="disabled" class="min-h-11 self-end rounded bg-slate-900 px-4 py-3 text-sm font-bold text-white disabled:opacity-50">下書きを保存する</button>
            </form>
        </details>

        <section class="space-y-4">
            <h2 class="text-lg font-bold">イベント一覧 <span class="text-xs font-normal text-slate-500">稼働中・予約を優先して20件</span></h2>
            @forelse($events as $event)
                <article wire:key="raid-event-{{ $event['id'] }}-{{ $event['state_version'] }}" class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-bold">{{ $event['name'] }}</h3>
                        <span class="text-sm font-bold">{{ $event['status_label'] }}</span>
                    </div>
                    <p class="mt-1 break-all text-xs text-slate-500">#{{ $event['id'] }} · {{ $event['event_key'] }}</p>
                    <p class="mt-2 text-sm">{{ $event['starts_at'] }} 〜 {{ $event['ends_at'] }}（{{ config('app.timezone') }}）</p>
                    @if($event['missed_start'])
                        <p class="mt-2 text-sm font-bold text-red-700">未開催のまま終了時刻を過ぎています。自動開始・延長はせず、取消と再計画が必要です。</p>
                    @endif
                    @if($event['sorties_pause_reason'])
                        <p class="mt-2 text-sm">停止理由: {{ $event['sorties_pause_reason'] }}</p>
                    @endif
                    @if($event['cycle'])
                        <p class="mt-3 font-bold">{{ $event['cycle']['stage_no'] ? '第'.$event['cycle']['stage_no'].'再臨' : '残響 '.$event['cycle']['echo_no'] }} · ボスHP {{ number_format($event['cycle']['current_hp']) }} / {{ number_format($event['cycle']['max_hp']) }}</p>
                    @endif
                    <dl class="mt-3 grid grid-cols-2 gap-3 border-y border-slate-100 py-3 text-sm sm:grid-cols-5">
                        @foreach(['確定出撃' => $event['resolved_count'], '精算・返却待ち' => $event['pending_count'], 'うち期限超過' => $event['stale_count'], '返却済み' => $event['refunded_count'], '系譜確定日数' => $event['lineages_determined'].' / 7'] as $label => $value)
                            <div><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 font-bold">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer">承認・ルールの記録</summary>
                        <dl class="mt-2 space-y-1 break-all text-xs text-slate-600">
                            <div><dt class="inline">承認日時: </dt><dd class="inline">{{ $event['balance_approved_at'] }}</dd></div>
                            <div><dt class="inline">承認者: </dt><dd class="inline">{{ $event['balance_approved_by_user_id'] ?? '未記録' }}</dd></div>
                            <div><dt class="inline">承認根拠: </dt><dd class="inline">{{ $event['balance_approval_reference'] ?? '未記録' }}</dd></div>
                            <div><dt class="inline">ルールSHA-256: </dt><dd class="inline">{{ $event['ruleset_hash'] }}</dd></div>
                        </dl>
                    </details>
                    @if(array_key_exists('pause', $event['actions']))
                        <label class="mt-4 block text-sm">出撃停止の理由
                            <input wire:model="pauseReason" type="text" maxlength="160" class="mt-1 w-full rounded border-slate-300" placeholder="停止する理由を記録してください">
                            @error('pauseReason')<span class="text-red-700">{{ $message }}</span>@enderror
                        </label>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($event['actions'] as $action => $label)
                            <button type="button" wire:click="operate({{ $event['id'] }}, {{ $event['state_version'] }}, '{{ $action }}')"
                                wire:confirm="{{ $label }}を実行しますか？{{ $action === 'schedule' ? '予約後は設定と開始条件が揃った時刻に自動開始します。' : '確定済みの戦闘履歴は変更しません。' }}"
                                wire:loading.attr="disabled" class="min-h-11 rounded border border-slate-300 px-4 py-2 text-sm font-bold disabled:opacity-50">{{ $label }}</button>
                        @endforeach
                        @if(in_array($event['status'], ['active', 'finalizing'], true))
                            <button type="button" wire:click="recoverExpiredSorties({{ $event['id'] }})" wire:confirm="このイベントの期限切れ出撃だけ返却を再試行しますか？" wire:loading.attr="disabled" class="min-h-11 rounded border border-slate-300 px-4 py-2 text-sm disabled:opacity-50">期限切れ返却を再試行</button>
                            <button type="button" wire:click="retryLineages({{ $event['id'] }})" wire:loading.attr="disabled" class="min-h-11 rounded border border-slate-300 px-4 py-2 text-sm disabled:opacity-50">日次系譜を再確認</button>
                        @endif
                    </div>
                    @if($event['status'] === 'finalizing')
                        <p class="mt-3 text-sm text-amber-800">精算・返却・全7日の系譜を確認後、運営CLIで最終確定してください。ブラウザでは重い確定処理を実行しません。個人の受取権利には期限を設けません。</p>
                        <code class="mt-2 block break-all text-xs">php artisan nation-raid:finalize {{ $event['id'] }} --confirm-rewards</code>
                    @endif
                </article>
            @empty
                <p class="text-sm text-slate-500">登録されたイベントはありません。</p>
            @endforelse
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="font-bold">精算・返却待ち <span class="text-xs font-normal text-slate-500">期限が近い20件</span></h2>
            <p class="mt-1 text-xs text-slate-500">期限前の出撃は強制返却しません。公開OFFでも回収処理は継続します。</p>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @forelse($pending as $battle)
                    <li class="flex flex-wrap justify-between gap-2 py-3">
                        <span>イベント #{{ $battle['event_id'] }} · 出撃 #{{ $battle['id'] }} · {{ $battle['day'] }}日目</span>
                        <span class="{{ $battle['stale'] ? 'font-bold text-red-700' : 'text-slate-600' }}">{{ $battle['status'] }} · 期限 {{ $battle['deadline'] }}{{ $battle['stale'] ? '（期限超過）' : '' }}</span>
                    </li>
                @empty
                    <li class="py-3 text-slate-500">精算・返却待ちはありません。</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
