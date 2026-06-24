<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-6">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">OPERATION SETTINGS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">運営・報酬設定</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">新規登録受付、ドロップ倍率、報酬上限、各種クールタイムなどを運用調整します。</p>
        </div>
        <button type="button" wire:click="save" wire:loading.attr="disabled" class="rounded-md bg-slate-950 px-5 py-3 text-sm font-black text-white shadow transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove>保存する</span>
            <span wire:loading>保存中...</span>
        </button>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @foreach($settings as $setting)
            <div class="rounded-md bg-white/95 p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="text-base font-black text-slate-950">{{ $setting->label }}</div>
                        <div class="mt-1 text-xs font-bold text-slate-500">{{ $setting->setting_key }}</div>
                        <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-600">{{ $setting->description }}</p>
                    </div>
                    <div class="w-full sm:w-44 shrink-0">
                        <label class="block text-xs font-black text-slate-500 mb-1">設定値</label>
                        @if($setting->value_type === 'boolean')
                            <label class="flex min-h-12 cursor-pointer items-center justify-between rounded-md border border-slate-300 bg-white px-3 py-2 shadow-sm">
                                <span class="text-sm font-black {{ ($values[$setting->id] ?? $setting->value) ? 'text-emerald-700' : 'text-slate-500' }}">
                                    {{ ($values[$setting->id] ?? $setting->value) ? '受付中' : '停止中' }}
                                </span>
                                <input type="checkbox"
                                       wire:model.live="values.{{ $setting->id }}"
                                       value="1"
                                       class="h-5 w-5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            </label>
                        @else
                            <input type="number"
                                   step="{{ $setting->value_type === 'integer' ? '1' : '0.01' }}"
                                   min="0"
                                   wire:model.defer="values.{{ $setting->id }}"
                                   class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-right text-lg font-black text-slate-950 shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-900">
        調整値は保存後すぐに反映されます。新規登録受付を停止しても、既存アカウントのログインは継続できます。倍率は 1.0 が現状維持、0.5 が半分、2.0 が2倍です。クールタイムは秒数で指定し、0にすると待機なしになります。
    </div>
</div>
