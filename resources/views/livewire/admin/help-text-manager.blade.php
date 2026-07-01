<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">HELP TEXTS</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">ヘルプ文言管理</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">
            ヘルプページと街の案内所で共通表示される説明文を編集できます。空欄、または初期値と同じ内容で保存するとデフォルトに戻ります。
        </p>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-5 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-xs font-bold leading-relaxed text-blue-700">
        本文はHTMLを使えます。戦闘待機秒数は <code class="rounded bg-white px-1 py-0.5 font-mono">&#123;&#123;battle_cooldown_seconds&#125;&#125;</code>、
        ヘルプ本文はHTMLで編集できます。既存文言の置換キーが残っている場合も、現在の宿屋待機秒数は0秒として扱われます。
    </div>

    <form wire:submit="save" class="space-y-5">
        <section class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-sm font-black text-slate-900">共通文言</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <label class="block">
                    <span class="text-xs font-black text-slate-500">上部案内文</span>
                    <input type="text" wire:model="instruction" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-500">下部補足文</span>
                    <input type="text" wire:model="footer" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                </label>
            </div>
        </section>

        <div class="space-y-4">
            @foreach($sections as $index => $section)
                <section wire:key="help-section-{{ $section['slug'] }}" x-data="{ open: false }" class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-slate-50">
                        <div class="min-w-0">
                            <h2 class="truncate text-sm font-black text-slate-900">{{ $index + 1 }}. {{ $section['title'] }}</h2>
                            <p class="mt-1 truncate text-[11px] font-mono font-bold text-slate-400">{{ $section['slug'] }}</p>
                        </div>
                        <span class="flex shrink-0 items-center gap-2 text-xs font-black text-slate-500">
                            <span x-text="open ? '閉じる' : '編集する'"></span>
                            <svg class="h-4 w-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </span>
                    </button>

                    <div x-show="open" x-transition class="space-y-3 border-t border-slate-100 px-4 py-4">
                        <label class="block">
                            <span class="text-xs font-black text-slate-500">アイコン画像パス</span>
                            <input type="text" wire:model="sections.{{ $index }}.icon_image" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-mono shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30" placeholder="images/icon/icon_000.webp">
                        </label>
                        <label class="block">
                            <span class="text-xs font-black text-slate-500">見出し</span>
                            <input type="text" wire:model="sections.{{ $index }}.title" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                        </label>
                        <label class="block">
                            <span class="text-xs font-black text-slate-500">本文</span>
                            <textarea wire:model="sections.{{ $index }}.body" rows="8" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-xs leading-relaxed shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30"></textarea>
                        </label>
                    </div>
                </section>
            @endforeach
        </div>

        <div class="sticky bottom-0 z-20 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-4 shadow-lg backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <div class="flex flex-wrap items-center gap-4">
                <button type="submit" class="rounded-md bg-amber-500 px-6 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-400">
                    保存する
                </button>
                <p class="text-xs font-bold text-slate-500">
                    保存後、ヘルプページと案内所へ反映されます。
                </p>
            </div>
        </div>
    </form>
</div>
