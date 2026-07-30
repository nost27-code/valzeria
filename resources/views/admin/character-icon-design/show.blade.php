<x-layouts.admin>
    @php
        $options = config('character_icon_design.options', []);
        $character = $designRequest->character;
        $displayRows = collect(config('character_icon_design.display_fields', []))
            ->map(function (array $field) use ($designRequest, $options): ?array {
                $rawValue = data_get($designRequest->form_data, $field['key']);
                $optionGroup = $field['options'] ?? null;
                $displayValue = $rawValue;

                if (is_array($rawValue)) {
                    $displayValue = collect($rawValue)
                        ->map(fn ($value) => $optionGroup ? data_get($options, $optionGroup.'.'.$value, $value) : $value)
                        ->implode('、');
                } elseif ($optionGroup && filled($rawValue)) {
                    $displayValue = data_get($options, $optionGroup.'.'.$rawValue, $rawValue);
                }

                if (! filled($displayValue)) {
                    return null;
                }

                return [
                    'label' => $field['label'],
                    'value' => $displayValue,
                ];
            })
            ->filter()
            ->values();
        $aiPromptText = $displayRows
            ->map(fn (array $row) => $row['label'].'：'.\Illuminate\Support\Str::squish((string) $row['value']))
            ->implode("\n");
    @endphp

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <a href="{{ route('admin.character-icon-design.index') }}" class="text-sm font-black text-violet-700 hover:text-violet-900">← 制作依頼一覧へ</a>

        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-black tracking-[0.24em] text-violet-600">DESIGN REQUEST #{{ $designRequest->id }}</p>
                <h1 class="mt-2 text-3xl font-black text-slate-950">{{ $character?->name ?? '削除済み冒険者' }}さんのキャラアイコン制作</h1>
                <p class="mt-2 text-sm font-bold text-slate-500">専用チャット内の本文と画像は、この依頼者と管理人だけが確認できます。</p>
            </div>
            <span class="w-fit rounded-full bg-violet-100 px-4 py-2 text-sm font-black text-violet-700">{{ $designRequest->statusLabel() }}</span>
        </div>

        @if(session('status'))
            <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800">入力内容を確認してください。</div>
        @endif

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <main class="space-y-6">
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-xl font-black text-slate-950">ヒアリング内容</h2>
                            <p class="mt-1 text-xs font-bold text-slate-500">各回答を「項目：内容」の形式でAI用プロンプトとしてコピーできます。</p>
                        </div>
                        @if($aiPromptText !== '')
                            <div
                                class="shrink-0"
                                x-data="{ copied: false, failed: false, copy() { if (!navigator.clipboard?.writeText) { this.failed = true; return; } navigator.clipboard.writeText(@js($aiPromptText)).then(() => { this.copied = true; this.failed = false; setTimeout(() => this.copied = false, 2500); }).catch(() => { this.failed = true; this.copied = false; }); } }"
                            >
                                <button type="button" x-on:click="copy" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-violet-700 px-4 text-xs font-black text-white shadow-sm hover:bg-violet-800 sm:w-auto">
                                    <span x-show="!copied && !failed">AIプロンプト用にコピー</span>
                                    <span x-cloak x-show="copied">コピーしました</span>
                                    <span x-cloak x-show="failed">コピーできませんでした</span>
                                </button>
                            </div>
                        @endif
                    </div>
                    @if($designRequest->form_data)
                        <dl class="mt-4 grid overflow-hidden rounded-xl border border-slate-200 sm:grid-cols-[13rem_minmax(0,1fr)]">
                            @foreach($displayRows as $row)
                                <dt class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-xs font-black text-slate-500">{{ $row['label'] }}</dt>
                                <dd class="whitespace-pre-wrap border-b border-slate-200 px-4 py-3 text-sm font-bold leading-6 text-slate-800">{{ $row['value'] }}</dd>
                            @endforeach
                        </dl>
                    @else
                        <div class="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm font-bold text-slate-500">ヒアリングシートはまだ提出されていません。</div>
                    @endif
                </section>

                @if($designRequest->isChatOpen())
                    <section id="design-chat" class="rounded-xl border border-indigo-200 bg-slate-50 p-4 shadow-sm sm:p-6">
                        <div>
                            <p class="text-xs font-black tracking-wider text-indigo-500">PRIVATE DESIGN CHAT</p>
                            <h2 class="mt-1 text-xl font-black text-slate-950">候補提示・微調整チャット</h2>
                            <p class="mt-2 text-sm font-bold text-slate-500">候補画像は1回のメッセージに最大4枚添付できます。</p>
                        </div>

                        <div class="mt-5 space-y-3">
                            @forelse($designRequest->messages as $message)
                                <article class="flex {{ $message->sender_type === 'admin' ? 'justify-end' : 'justify-start' }}">
                                    <div class="w-full max-w-2xl rounded-2xl border px-4 py-3 shadow-sm {{ $message->sender_type === 'admin' ? 'border-indigo-200 bg-indigo-100' : 'border-slate-200 bg-white' }}">
                                        <div class="flex items-center justify-between gap-3 text-xs font-black">
                                            <span class="{{ $message->sender_type === 'admin' ? 'text-indigo-800' : 'text-slate-700' }}">
                                                {{ $message->sender_type === 'admin' ? '管理人' : ($character?->name ?? '冒険者') }}
                                            </span>
                                            <time class="font-bold text-slate-400">{{ $message->created_at?->format('Y/m/d H:i') }}</time>
                                        </div>
                                        @if(filled($message->body))
                                            <p class="mt-2 whitespace-pre-wrap text-sm font-semibold leading-7 text-slate-800">{{ $message->body }}</p>
                                        @endif
                                        @if($message->attachments->isNotEmpty())
                                            <div class="mt-3 grid grid-cols-2 gap-2">
                                                @foreach($message->attachments as $attachment)
                                                    <div class="space-y-1.5" data-attachment-number="{{ $loop->iteration }}">
                                                        <div class="flex items-center gap-1.5 text-xs font-black text-slate-700">
                                                            <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-slate-900 px-1.5 text-white">{{ $loop->iteration }}</span>
                                                            <span>{{ $loop->iteration }}番</span>
                                                        </div>
                                                        <a href="{{ route('admin.character-icon-design.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="group block overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                                                            <img src="{{ route('admin.character-icon-design.attachments.show', $attachment) }}" alt="{{ $loop->iteration }}番：{{ $attachment->original_name }}" class="aspect-square w-full object-cover transition group-hover:scale-[1.02]">
                                                        </a>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm font-bold text-slate-500">まだメッセージはありません。最初の候補や制作開始の連絡を送れます。</div>
                            @endforelse
                        </div>

                        @if($designRequest->status !== 'completed')
                            <form method="POST" action="{{ route('admin.character-icon-design.messages.store', $designRequest) }}" enctype="multipart/form-data" class="mt-5 space-y-3 rounded-xl border border-slate-200 bg-white p-4" data-submit-lock data-loading-text="送信中...">
                                @csrf
                                <label class="block">
                                    <span class="text-sm font-black text-slate-800">冒険者へのメッセージ</span>
                                    <textarea name="body" rows="5" maxlength="3000" placeholder="例：まず4案を用意しました。残したい髪型や衣装、色合いを教えてください。" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold leading-6">{{ old('body') }}</textarea>
                                    @error('body')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <x-multi-image-picker label="候補画像" />
                                @error('attachments')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                @error('attachments.*')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-indigo-700 px-5 text-sm font-black text-white hover:bg-indigo-800 sm:w-auto">専用チャットへ送信</button>
                            </form>
                        @else
                            <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">制作完了のため、チャットは履歴確認用です。</div>
                        @endif
                    </section>
                @endif
            </main>

            <aside class="space-y-4">
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-base font-black text-slate-950">依頼情報</h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div>
                            <dt class="text-xs font-black text-slate-400">提出時の輝石支払い</dt>
                            <dd class="mt-1 font-bold text-slate-800">{{ $designRequest->purchased_at?->format('Y/m/d H:i') ?? '未確認' }}</dd>
                        </div>
                        @if($designRequest->purchased_at)
                            <div>
                                <dt class="text-xs font-black text-slate-400">輝石消費内訳</dt>
                                <dd class="mt-1 font-bold text-slate-800">合計{{ number_format($designRequest->price_kiseki) }}（無償{{ number_format($designRequest->free_kiseki_spent) }} / 有償{{ number_format($designRequest->paid_kiseki_spent) }}）</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-xs font-black text-slate-400">シート提出</dt>
                            <dd class="mt-1 font-bold text-slate-800">{{ $designRequest->submitted_at?->format('Y/m/d H:i') ?? '未提出' }}</dd>
                        </div>
                    </dl>
                </section>

                @if($designRequest->submitted_at)
                    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 class="text-base font-black text-slate-950">進行状態を更新</h2>
                        <form method="POST" action="{{ route('admin.character-icon-design.status.update', $designRequest) }}" class="mt-3 space-y-3" data-submit-lock data-loading-text="更新中...">
                            @csrf
                            @method('PATCH')
                            <select name="status" class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800">
                                @foreach(config('character_icon_design.admin_editable_statuses', []) as $status)
                                    <option value="{{ $status }}" @selected($designRequest->status === $status)>{{ config('character_icon_design.statuses.'.$status, $status) }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-4 text-sm font-black text-white hover:bg-slate-800">進行状態を保存</button>
                        </form>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.admin>
