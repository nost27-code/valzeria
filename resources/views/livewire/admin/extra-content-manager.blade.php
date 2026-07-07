<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-emerald-600">EXTRA CONTENT</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">追加コンテンツON/OFF</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">エクスト塔や地下コンテンツの公開状態と開催期間を切り替えます。ONかつ開催期間内の時だけプレイヤーに表示されます。</p>
        </div>
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

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @foreach($contents as $content)
            @php
                $enabled = (bool) ($content['enabled'] ?? false);
                $active = (bool) ($content['active'] ?? false);
                $period = $content['period'] ?? [];
                $routeName = (string) ($content['route'] ?? '');
                $hasRoute = $routeName !== '' && \Illuminate\Support\Facades\Route::has($routeName);
            @endphp

            <section class="rounded-md bg-white/95 p-5 shadow-sm ring-1 {{ $active ? 'ring-emerald-200' : 'ring-slate-200' }}">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-black text-slate-500">{{ $content['category'] }}</span>
                            <span class="rounded px-2 py-1 text-[11px] font-black {{ $enabled ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200' }}">
                                {{ $enabled ? 'ON' : 'OFF' }}
                            </span>
                            <span class="rounded px-2 py-1 text-[11px] font-black {{ $active ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' }}">
                                {{ !$enabled ? '非表示' : ($active ? '表示中' : ($period['status_label'] ?? '期間外')) }}
                            </span>
                            @unless($content['default_enabled'] ?? false)
                                <span class="rounded bg-slate-100 px-2 py-1 text-[11px] font-black text-slate-500 ring-1 ring-slate-200">既定OFF</span>
                            @endunless
                        </div>
                        <h2 class="mt-3 text-lg font-black text-slate-950">{{ $content['name'] }}</h2>
                        <p class="mt-1 text-xs font-bold text-slate-400">{{ $content['key'] }}</p>
                        <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-600">{{ $content['description'] }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-black text-slate-500">
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $content['enabled_setting_key'] }}</span>
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $content['starts_at_setting_key'] }}</span>
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $content['ends_at_setting_key'] }}</span>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2 sm:w-36">
                        <button type="button"
                                wire:click="toggle('{{ $content['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="toggle('{{ $content['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md px-4 py-3 text-sm font-black shadow-sm transition disabled:cursor-wait disabled:opacity-60 {{ $enabled ? 'bg-slate-950 text-white hover:bg-slate-800' : 'bg-emerald-600 text-white hover:bg-emerald-700' }}">
                            <span wire:loading.remove wire:target="toggle('{{ $content['key'] }}')">
                                {{ $enabled ? 'OFFにする' : 'ONにする' }}
                            </span>
                            <span wire:loading wire:target="toggle('{{ $content['key'] }}')">保存中...</span>
                        </button>
                        @if($hasRoute)
                            <a href="{{ route($routeName) }}"
                               class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50">
                                画面を開く
                            </a>
                        @endif
                    </div>
                </div>

                <div class="mt-5 rounded-md border border-slate-200 bg-slate-50/70 p-4">
                    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-sm font-black text-slate-900">開催期間</div>
                            <p class="mt-1 text-xs font-bold text-slate-500">日本時間で判定します。開始が空なら即時開始、終了が空なら無期限です。</p>
                        </div>
                        <div class="text-xs font-black text-slate-500">
                            {{ $period['status_label'] ?? '未設定' }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-black text-slate-500">開催開始</span>
                            <input type="datetime-local"
                                   wire:model.defer="periodInputs.{{ $content['key'] }}.starts_at"
                                   class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-900 shadow-sm focus:border-emerald-400 focus:ring focus:ring-emerald-200">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-black text-slate-500">開催終了</span>
                            <input type="datetime-local"
                                   wire:model.defer="periodInputs.{{ $content['key'] }}.ends_at"
                                   class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-900 shadow-sm focus:border-emerald-400 focus:ring focus:ring-emerald-200">
                        </label>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button"
                                wire:click="savePeriod('{{ $content['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="savePeriod('{{ $content['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-xs font-black text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="savePeriod('{{ $content['key'] }}')">期間を保存</span>
                            <span wire:loading wire:target="savePeriod('{{ $content['key'] }}')">保存中...</span>
                        </button>
                        <button type="button"
                                wire:click="clearPeriod('{{ $content['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="clearPeriod('{{ $content['key'] }}')"
                                class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-black text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="clearPeriod('{{ $content['key'] }}')">期間クリア</span>
                            <span wire:loading wire:target="clearPeriod('{{ $content['key'] }}')">クリア中...</span>
                        </button>
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-900">
        設定は保存直後から街・探索一覧・直URLの入場判定に反映されます。新しい塔や地下コンテンツを増やす場合は <span class="font-black">config/extra_content.php</span> に項目を追加してください。
    </div>
</div>
