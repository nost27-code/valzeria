<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">ADVENTURE SUPPORT</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">補給商会 商品ON/OFF</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">補給商会の商品販売状態と表示状態を切り替えます。販売OFFは購入不可、非表示は一覧から隠します。</p>
        </div>
        <a href="{{ route('kiseki.support') }}"
           class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50">
            補給商会を開く
        </a>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-black text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <section class="mb-5 rounded-md bg-white/95 p-5 shadow-sm ring-1 {{ $supportPassEnabled ? 'ring-emerald-200' : 'ring-slate-200' }}">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-black text-slate-500">冒険者支援パス</span>
                    <span class="rounded px-2 py-1 text-[11px] font-black {{ $supportPassEnabled ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200' }}">
                        {{ $supportPassEnabled ? '全体ON' : '全体OFF' }}
                    </span>
                </div>
                <h2 class="mt-3 text-lg font-black text-slate-950">冒険者支援パス公開状態</h2>
                <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-600">
                    OFFにすると、補給商会の商品一覧から冒険者支援パスを非表示にし、購入・延長も停止します。すでに有効なパスの期限データは削除しません。
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-black text-slate-500">
                    <span class="rounded bg-slate-100 px-2 py-1">{{ \App\Services\SupportPassService::ENABLED_SETTING_KEY }}</span>
                    <span class="rounded bg-slate-100 px-2 py-1">商品側の支援パス30日ON/OFFとは別の全体スイッチ</span>
                </div>
            </div>

            <button type="button"
                    wire:click="toggleSupportPass"
                    wire:loading.attr="disabled"
                    wire:target="toggleSupportPass"
                    class="inline-flex shrink-0 items-center justify-center rounded-md px-4 py-3 text-sm font-black shadow-sm transition disabled:cursor-wait disabled:opacity-60 {{ $supportPassEnabled ? 'bg-slate-950 text-white hover:bg-slate-800' : 'bg-emerald-600 text-white hover:bg-emerald-700' }}">
                <span wire:loading.remove wire:target="toggleSupportPass">
                    {{ $supportPassEnabled ? 'OFFにする' : 'ONにする' }}
                </span>
                <span wire:loading wire:target="toggleSupportPass">保存中...</span>
            </button>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @foreach($items as $item)
            @php
                $enabled = (bool) ($item['enabled'] ?? false);
                $visible = (bool) ($item['visible'] ?? true);
                $currencyLabel = ($item['currency'] ?? 'kiseki') === 'gold' ? 'G' : '輝石';
                $defaultEnabled = (bool) ($item['default_enabled'] ?? true);
                $defaultVisible = (bool) ($item['default_visible'] ?? true);
            @endphp
            <section class="rounded-md bg-white/95 p-5 shadow-sm ring-1 {{ $enabled && $visible ? 'ring-emerald-200' : 'ring-slate-200' }}">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-black text-slate-500">{{ $item['category'] }}</span>
                            <span class="rounded px-2 py-1 text-[11px] font-black {{ $enabled ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200' }}">
                                {{ $enabled ? '販売ON' : '販売OFF' }}
                            </span>
                            <span class="rounded px-2 py-1 text-[11px] font-black {{ $visible ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200' }}">
                                {{ $visible ? '表示中' : '非表示' }}
                            </span>
                            @if($item['campaign']['scheduled'] ?? false)
                                <span class="rounded px-2 py-1 text-[11px] font-black {{ ($item['campaign']['active'] ?? false) ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' }}">
                                    キャンペーン{{ $item['campaign']['status_label'] ?? '' }}
                                </span>
                            @endif
                            @unless($defaultEnabled)
                                <span class="rounded bg-amber-50 px-2 py-1 text-[11px] font-black text-amber-700 ring-1 ring-amber-200">既定OFF</span>
                            @endunless
                            @unless($defaultVisible)
                                <span class="rounded bg-amber-50 px-2 py-1 text-[11px] font-black text-amber-700 ring-1 ring-amber-200">既定非表示</span>
                            @endunless
                        </div>
                        <h2 class="mt-3 text-lg font-black text-slate-950">{{ $item['name'] }}</h2>
                        <p class="mt-1 text-xs font-bold text-slate-400">{{ $item['key'] }}</p>
                        <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-600">{{ $item['description'] }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-black text-slate-500">
                            <span class="rounded bg-slate-100 px-2 py-1">通常価格 {{ number_format((int) ($item['price'] ?? 0)) }}{{ $currencyLabel }}</span>
                            @if($item['campaign']['price'] ?? null)
                                <span class="rounded bg-red-50 px-2 py-1 text-red-700">キャンペーン価格 {{ number_format((int) $item['campaign']['price']) }}{{ $currencyLabel }}</span>
                            @endif
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $item['setting_key'] }}</span>
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $item['visibility_setting_key'] }}</span>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2 sm:w-32">
                        <button type="button"
                                wire:click="toggle('{{ $item['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="toggle('{{ $item['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md px-4 py-3 text-sm font-black shadow-sm transition disabled:cursor-wait disabled:opacity-60 {{ $enabled ? 'bg-slate-950 text-white hover:bg-slate-800' : 'bg-emerald-600 text-white hover:bg-emerald-700' }}">
                            <span wire:loading.remove wire:target="toggle('{{ $item['key'] }}')">
                                {{ $enabled ? '販売OFF' : '販売ON' }}
                            </span>
                            <span wire:loading wire:target="toggle('{{ $item['key'] }}')">保存中...</span>
                        </button>
                        <button type="button"
                                wire:click="toggleVisibility('{{ $item['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="toggleVisibility('{{ $item['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md border px-4 py-3 text-sm font-black shadow-sm transition disabled:cursor-wait disabled:opacity-60 {{ $visible ? 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' : 'border-sky-200 bg-sky-600 text-white hover:bg-sky-700' }}">
                            <span wire:loading.remove wire:target="toggleVisibility('{{ $item['key'] }}')">
                                {{ $visible ? '非表示' : '表示' }}
                            </span>
                            <span wire:loading wire:target="toggleVisibility('{{ $item['key'] }}')">保存中...</span>
                        </button>
                    </div>
                </div>

                <div class="mt-5 rounded-md border border-slate-200 bg-slate-50/70 p-4">
                    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-sm font-black text-slate-900">キャンペーン設定</div>
                            <p class="mt-1 text-xs font-bold text-slate-500">日本時間で開始/終了を指定します。期間中はキャンペーン価格で販売されます。</p>
                        </div>
                        <div class="text-xs font-black text-slate-500">
                            {{ $item['campaign']['status_label'] ?? '未設定' }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                        <label class="block">
                            <span class="mb-1 block text-xs font-black text-slate-500">キャンペーン価格</span>
                            <input type="number"
                                   min="0"
                                   step="1"
                                   wire:model.defer="campaignInputs.{{ $item['key'] }}.price"
                                   class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-900 shadow-sm focus:border-amber-400 focus:ring focus:ring-amber-200">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-black text-slate-500">開始日時</span>
                            <input type="datetime-local"
                                   wire:model.defer="campaignInputs.{{ $item['key'] }}.starts_at"
                                   class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-900 shadow-sm focus:border-amber-400 focus:ring focus:ring-amber-200">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-black text-slate-500">終了日時</span>
                            <input type="datetime-local"
                                   wire:model.defer="campaignInputs.{{ $item['key'] }}.ends_at"
                                   class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-900 shadow-sm focus:border-amber-400 focus:ring focus:ring-amber-200">
                        </label>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button"
                                wire:click="saveCampaign('{{ $item['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="saveCampaign('{{ $item['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md bg-amber-600 px-4 py-2 text-xs font-black text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="saveCampaign('{{ $item['key'] }}')">キャンペーン保存</span>
                            <span wire:loading wire:target="saveCampaign('{{ $item['key'] }}')">保存中...</span>
                        </button>
                        <button type="button"
                                wire:click="clearCampaign('{{ $item['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="clearCampaign('{{ $item['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-black text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="clearCampaign('{{ $item['key'] }}')">クリア</span>
                            <span wire:loading wire:target="clearCampaign('{{ $item['key'] }}')">クリア中...</span>
                        </button>
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-900">
        設定は保存直後から購入判定に反映されます。冒険者支援パスは、全体ONかつ商品「冒険者支援パス 30日」が表示中・販売ONの時だけ販売されます。
    </div>
</div>
