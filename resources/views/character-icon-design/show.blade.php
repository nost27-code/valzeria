<x-layouts.facility title="キャラアイコン制作" headerIcon="✦" bgImage="images/bg-castle.webp" :showGameHeader="true">
    @php
        $price = (int) ($designRequest?->price_kiseki
            ?? config('character_icon_design.submission_price_kiseki', 40));
        $options = config('character_icon_design.options', []);
        $oneLineExamples = config('character_icon_design.one_line_examples', []);
        $savedForm = $designRequest?->form_data ?? [];
        $formValue = fn (string $key, mixed $default = null) => old($key, data_get($savedForm, $key, $default));
    @endphp

    <div class="mx-auto w-full max-w-4xl px-3 py-5 sm:px-6 sm:py-8">
        @if(session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">
                {{ session('status') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800">
                {{ session('error') }}
            </div>
        @endif
        @if(session('character_icon_design_preparing_message'))
            <div x-data="{ open: true }">
                <template x-teleport="body">
                    <div x-cloak x-show="open" @keydown.escape.window="open = false" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4" role="presentation">
                        <section @click.outside="open = false" role="dialog" aria-modal="true" aria-labelledby="character-icon-preparing-title" class="w-full max-w-md overflow-hidden rounded-2xl border-2 border-[#d4af37] bg-white shadow-2xl">
                            <header class="bg-gradient-to-r from-violet-950 via-indigo-950 to-slate-950 px-5 py-4 text-white">
                                <h2 id="character-icon-preparing-title" class="text-lg font-black">キャラアイコン作成</h2>
                            </header>
                            <div class="px-5 py-6">
                                <p class="text-sm font-bold leading-7 text-slate-700">{{ session('character_icon_design_preparing_message') }}</p>
                            </div>
                            <footer class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-right">
                                <button type="button" @click="open = false" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-violet-700 px-5 text-sm font-black text-white hover:bg-violet-800">
                                    閉じる
                                </button>
                            </footer>
                        </section>
                    </div>
                </template>
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-violet-200 bg-white shadow-sm">
            <div class="bg-gradient-to-r from-violet-950 via-indigo-950 to-slate-950 px-4 py-5 text-white sm:px-6">
                <p class="text-xs font-black tracking-[0.2em] text-violet-200">CHARACTER ICON DESIGN</p>
                <h1 class="mt-2 text-2xl font-black">キャラアイコン制作 ヒアリングシート</h1>
                <p class="mt-2 text-sm font-bold leading-7 text-violet-100">
                    迷う項目は「おまかせ」で大丈夫です。選択肢を中心に、最後にこだわりとNGを教えてください。
                </p>
            </div>

            <div class="border-b border-amber-200 bg-amber-50 px-4 py-4 sm:px-6">
                <p class="text-sm font-black text-amber-950">エフェクト表現について</p>
                <p class="mt-1 text-sm font-bold leading-6 text-amber-900">
                    キャラクター本体を見やすく、アイコン全体の雰囲気を揃えるため、花びら・蝶・光など、キャラクターの周囲に浮かぶ演出エフェクトは基本的に描かない方針です。衣装・髪飾り・手に持つ小物など、キャラクター自身のデザインに含まれる装飾はご相談いただけます。
                </p>
            </div>

            <nav class="grid grid-cols-2 gap-2 border-b border-violet-100 bg-slate-50 p-3 sm:p-4" aria-label="キャラアイコン制作の表示切り替え">
                <a
                    href="{{ route('character-icon-design.show', ['view' => 'new']) }}"
                    class="flex min-h-12 items-center justify-center gap-2 rounded-xl border px-3 text-sm font-black transition {{ $viewMode === 'new' ? 'border-violet-700 bg-violet-700 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:border-violet-300 hover:text-violet-700' }}"
                    @if($viewMode === 'new') aria-current="page" @endif
                >
                    <span>新規作成</span>
                    @if($draftRequest)
                        <span class="rounded-full px-2 py-0.5 text-[10px] {{ $viewMode === 'new' ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-800' }}">下書きあり</span>
                    @endif
                </a>
                <a
                    href="{{ route('character-icon-design.show', ['view' => 'submitted']) }}"
                    class="flex min-h-12 items-center justify-center gap-2 rounded-xl border px-3 text-sm font-black transition {{ $viewMode === 'submitted' ? 'border-violet-700 bg-violet-700 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:border-violet-300 hover:text-violet-700' }}"
                    @if($viewMode === 'submitted') aria-current="page" @endif
                >
                    <span>提出済み</span>
                    <span class="rounded-full px-2 py-0.5 text-[10px] {{ $viewMode === 'submitted' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' }}">{{ number_format($submittedRequests->count()) }}</span>
                </a>
            </nav>

            @if(in_array($viewMode, ['new', 'edit'], true))
                <form method="POST" action="{{ route('character-icon-design.form.save') }}" class="space-y-4 p-3 sm:p-6" data-submit-lock @if($viewMode === 'new') data-character-icon-autosave @endif data-loading-text="保存中..." x-data="{ exampleModalOpen: false }">
                    @csrf
                    <input type="hidden" name="usage_scenes[]" value="game_avatar">
                    <input type="hidden" name="intent" value="{{ $viewMode === 'edit' ? 'confirm' : 'draft' }}" @if($viewMode === 'new') data-character-icon-intent @endif>
                    @if($viewMode === 'edit')
                        <input type="hidden" name="design_request_id" value="{{ $designRequest->id }}">
                    @endif

                    @if($errors->any())
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            <p class="font-black">入力内容を確認してください</p>
                            <p class="mt-1 font-bold leading-6">「確認」に進むには、次の項目を入力・選択してください。</p>
                            <ul class="mt-2 space-y-1 font-bold leading-6">
                                @foreach($errors->all() as $error)
                                    <li>・{{ $error }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs font-bold leading-5 text-rose-700">下書き保存は、未入力の項目があっても利用できます。</p>
                        </div>
                    @endif

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-black text-slate-500">プレイヤー名</div>
                        <div class="mt-1 text-lg font-black text-slate-950">{{ $character->name }}</div>
                    </div>

                    @if($viewMode === 'edit')
                        <div class="rounded-xl border border-indigo-300 bg-indigo-50 p-4">
                            <div class="text-sm font-black text-indigo-950">提出済みのヒアリング内容を修正</div>
                            <p class="mt-1 text-sm font-bold leading-6 text-indigo-900">
                                修正による輝石の追加消費はありません。保存すると管理人へ回答更新のお知らせが届きます。
                            </p>
                        </div>
                    @else
                        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                            <div class="text-sm font-black text-amber-950">記入と下書き保存は無料です</div>
                            @if($submissionAvailable)
                                <p class="mt-1 text-sm font-bold leading-6 text-amber-900">
                                    提出時に{{ number_format($price) }}輝石を支払います。輝石は無償分から優先して消費します。
                                    現在の所持輝石は{{ number_format($totalKiseki) }}輝石です。
                                </p>
                                @if($totalKiseki < $price)
                                    <a href="{{ route('kiseki.shop') }}" class="mt-3 inline-flex min-h-10 items-center justify-center rounded-lg bg-amber-700 px-4 text-sm font-black text-white hover:bg-amber-800">
                                        輝石ショップを見る
                                    </a>
                                @endif
                            @else
                                <p class="mt-1 text-sm font-bold leading-6 text-amber-900">
                                    現在は下書き保存まで利用できます。提出受付の開始後、確認画面から{{ number_format($price) }}輝石を支払って提出できます。
                                </p>
                            @endif
                        </div>
                    @endif

                    <details open class="rounded-xl border border-violet-200 bg-white">
                        <summary class="cursor-pointer list-none px-4 py-4 text-base font-black text-violet-950">ヒアリングシート</summary>
                        <div class="space-y-5 border-t border-violet-100 px-4 py-5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach([
                                    ['priority', 'キャラのイメージ優先度', 'priority'],
                                    ['gender', '性別イメージ', 'gender'],
                                    ['age', '年齢イメージ', 'age'],
                                    ['role', '職業や立場', 'role'],
                                    ['held_item', '武器や小物', 'held_item'],
                                    ['region', '地域イメージ', 'region'],
                                ] as [$name, $label, $group])
                                    <label class="block">
                                        <span class="text-sm font-black text-slate-800">{{ $label }} <span class="text-rose-600">*</span></span>
                                        <select name="{{ $name }}" class="mt-1.5 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800">
                                            <option value="">選んでください</option>
                                            @foreach($options[$group] as $value => $optionLabel)
                                                <option value="{{ $value }}" @selected($formValue($name) === $value)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                        @error($name)<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-black text-slate-800">その他の職業・立場</span>
                                    <input type="text" name="role_other" value="{{ $formValue('role_other') }}" maxlength="200" placeholder="「その他」を選んだ場合" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">
                                    @error('role_other')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="block">
                                    <span class="text-sm font-black text-slate-800">その他の武器・小物</span>
                                    <input type="text" name="held_item_other" value="{{ $formValue('held_item_other') }}" maxlength="200" placeholder="「その他」を選んだ場合" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">
                                    @error('held_item_other')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                </label>
                            </div>

                            @foreach([
                                ['atmosphere', '雰囲気', 'atmosphere', 5],
                                ['motifs', '入れたいモチーフ', 'motifs', 6],
                            ] as [$name, $label, $group, $max])
                                <fieldset>
                                    <legend class="text-sm font-black text-slate-800">{{ $label }} <span class="text-rose-600">*</span> <span class="text-xs text-slate-400">（最大{{ $max }}個）</span></legend>
                                    @php
                                        $selectedValues = (array) $formValue($name, []);
                                    @endphp
                                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        @foreach($options[$group] as $value => $optionLabel)
                                            <label class="flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700">
                                                <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked(in_array($value, $selectedValues, true)) class="rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                                                <span>{{ $optionLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error($name)<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
                                </fieldset>
                            @endforeach

                            <div class="grid gap-3 sm:grid-cols-3">
                                @foreach([1, 2, 3] as $colorNo)
                                    <label class="block">
                                        <span class="text-sm font-black text-slate-800">好きな色 {{ $colorNo }} @if($colorNo === 1)<span class="text-rose-600">*</span>@endif</span>
                                        <input type="text" name="main_color_{{ $colorNo }}" value="{{ $formValue('main_color_'.$colorNo) }}" maxlength="50" placeholder="{{ $colorNo === 1 ? '例：深い青' : '任意' }}" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">
                                        @error('main_color_'.$colorNo)<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                            </div>

                            <label class="block">
                                <span class="text-sm font-black text-slate-800">絶対に入れたい要素</span>
                                <textarea name="must_have" rows="3" maxlength="2000" placeholder="例：銀髪、青いマント、星形の髪飾り" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold leading-6">{{ $formValue('must_have') }}</textarea>
                                @error('must_have')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                            </label>

                            <label class="block">
                                <span class="text-sm font-black text-slate-800">避けてほしい要素（NG）</span>
                                <textarea name="ng_elements" rows="3" maxlength="2000" placeholder="例：重装、可愛すぎる雰囲気、黒髪" class="mt-1.5 w-full rounded-lg border border-rose-200 bg-rose-50/40 px-3 py-2 text-sm font-semibold leading-6">{{ $formValue('ng_elements') }}</textarea>
                                <p class="mt-1 text-xs font-bold text-slate-500">満足度に直結するため、苦手なものを先に教えてください。</p>
                                @error('ng_elements')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                            </label>

                            <div class="block">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <label for="character-icon-one-line" class="text-sm font-black text-slate-800">一言で表すとどんなキャラ？</label>
                                    <button type="button" @click="exampleModalOpen = true" class="text-sm font-black text-violet-700 underline decoration-violet-300 underline-offset-4 hover:text-violet-900">
                                        サンプルを見る
                                    </button>
                                </div>
                                <input id="character-icon-one-line" type="text" name="one_line" value="{{ $formValue('one_line') }}" maxlength="300" placeholder="例：優しい雰囲気の星読み司書" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">
                                @error('one_line')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                            </div>

                            <template x-teleport="body">
                                <div x-cloak x-show="exampleModalOpen" @keydown.escape.window="exampleModalOpen = false" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-3 sm:p-6" role="presentation">
                                    <section @click.outside="exampleModalOpen = false" role="dialog" aria-modal="true" aria-labelledby="character-icon-example-title" class="flex max-h-[90dvh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-violet-200 bg-white shadow-2xl">
                                        <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-violet-950 px-4 py-4 text-white sm:px-6">
                                            <div>
                                                <p class="text-xs font-black tracking-wider text-violet-200">CHARACTER IDEAS</p>
                                                <h2 id="character-icon-example-title" class="mt-1 text-lg font-black sm:text-xl">「一言で表すキャラ」のサンプル</h2>
                                            </div>
                                            <button type="button" @click="exampleModalOpen = false" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-xl font-black hover:bg-white/20" aria-label="サンプルを閉じる">×</button>
                                        </header>

                                        <div class="overflow-y-auto overscroll-contain p-3 sm:p-6">
                                            <p class="mb-4 text-sm font-bold leading-6 text-slate-600">
                                                そのまま使わず、好きな要素を組み合わせて書いても大丈夫です。
                                            </p>
                                            <div class="grid gap-4 lg:grid-cols-2">
                                                @foreach($oneLineExamples as $category => $examples)
                                                    <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                                        <h3 class="text-base font-black text-violet-900">{{ $category }}</h3>
                                                        <ul class="mt-3 space-y-2">
                                                            @foreach($examples as $example)
                                                                <li class="flex gap-2 text-sm font-semibold leading-6 text-slate-700">
                                                                    <span class="shrink-0 text-violet-500">・</span>
                                                                    <span>{{ $example }}</span>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </section>
                                                @endforeach
                                            </div>
                                        </div>

                                        <footer class="border-t border-slate-200 bg-white px-4 py-3 text-right sm:px-6">
                                            <button type="button" @click="exampleModalOpen = false" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-black text-white hover:bg-slate-800">
                                                閉じる
                                            </button>
                                        </footer>
                                    </section>
                                </div>
                            </template>
                        </div>
                    </details>

                    <details class="rounded-xl border border-slate-200 bg-white">
                        <summary class="cursor-pointer list-none px-4 py-4 text-base font-black text-slate-900">もっと細かく指定する（任意）</summary>
                        <div class="space-y-5 border-t border-slate-100 px-4 py-5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach([
                                    ['body_type', '体格', 'body_type'],
                                    ['hair_color', '髪色', 'hair_color'],
                                    ['face_impression', '顔の印象', 'face_impression'],
                                    ['weapon_mood', '武器の雰囲気', 'weapon_mood'],
                                    ['expression', '表情', 'expression'],
                                ] as [$name, $label, $group])
                                    <label class="block">
                                        <span class="text-sm font-black text-slate-800">{{ $label }}</span>
                                        <select name="{{ $name }}" class="mt-1.5 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800">
                                            <option value="">指定しない</option>
                                            @foreach($options[$group] as $value => $optionLabel)
                                                <option value="{{ $value }}" @selected($formValue($name) === $value)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                        @error($name)<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                            </div>

                            @foreach([
                                ['hairstyles', '髪型', 'hairstyles', 5],
                                ['additional_elements', '眼鏡・耳・帽子など', 'additional_elements', 6],
                                ['outfit_directions', '衣装の方向性', 'outfit_directions', 5],
                                ['personalities', '性格イメージ', 'personalities', 5],
                            ] as [$name, $label, $group, $max])
                                <fieldset>
                                    <legend class="text-sm font-black text-slate-800">{{ $label }} <span class="text-xs text-slate-400">（最大{{ $max }}個）</span></legend>
                                    @php
                                        $selectedValues = (array) $formValue($name, []);
                                    @endphp
                                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        @foreach($options[$group] as $value => $optionLabel)
                                            <label class="flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700">
                                                <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked(in_array($value, $selectedValues, true)) class="rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                                                <span>{{ $optionLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error($name)<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
                                </fieldset>
                            @endforeach

                            <label class="block">
                                <span class="text-sm font-black text-slate-800">避けたい色</span>
                                <input type="text" name="avoid_colors" value="{{ $formValue('avoid_colors') }}" maxlength="200" placeholder="例：蛍光色、真っ赤" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">
                            </label>

                            <label class="block">
                                <span class="text-sm font-black text-slate-800">参考にしたい雰囲気・世界観</span>
                                <textarea name="reference_mood" rows="4" maxlength="2000" placeholder="ゲーム、職業、世界観など。既存作品の完全な再現ではなく、雰囲気の参考として記入してください。" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold leading-6">{{ $formValue('reference_mood') }}</textarea>
                            </label>
                        </div>
                    </details>

                    <div class="sticky bottom-2 z-10 flex flex-col gap-2 rounded-xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur sm:static sm:flex-row sm:items-center sm:justify-end sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none">
                        @if($viewMode === 'edit')
                            <a href="{{ route('character-icon-design.show', ['request' => $designRequest->id]) }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-black text-slate-700 hover:bg-slate-50">
                                修正をやめる
                            </a>
                            <button type="submit" name="intent" value="confirm" data-loading-text="保存中..." class="inline-flex min-h-12 items-center justify-center rounded-xl bg-violet-700 px-5 text-sm font-black text-white shadow hover:bg-violet-800">
                                修正内容を保存
                            </button>
                        @else
                            <p class="text-center text-xs font-bold text-slate-500 sm:mr-auto sm:text-left" data-character-icon-autosave-status role="status" aria-live="polite">
                                変更内容は自動で下書き保存されます
                            </p>
                            <button type="submit" name="intent" value="draft" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-black text-slate-700 hover:bg-slate-50">
                                下書きを保存
                            </button>
                            <button type="submit" name="intent" value="confirm" data-loading-text="確認中..." class="inline-flex min-h-12 items-center justify-center rounded-xl bg-violet-700 px-5 text-sm font-black text-white shadow hover:bg-violet-800">
                                確認
                            </button>
                        @endif
                    </div>
                </form>
            @elseif($designRequest)
                <div class="space-y-5 p-3 sm:p-6">
                    <section class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4">
                        <h2 class="text-sm font-black text-slate-900">提出済みの依頼を選択</h2>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach($submittedRequests as $submittedRequest)
                                <a
                                    href="{{ route('character-icon-design.show', ['request' => $submittedRequest->id]) }}"
                                    class="rounded-lg border px-3 py-3 transition {{ $designRequest->is($submittedRequest) ? 'border-violet-500 bg-violet-50 ring-1 ring-violet-200' : 'border-slate-200 bg-slate-50 hover:border-violet-300 hover:bg-violet-50' }}"
                                    @if($designRequest->is($submittedRequest)) aria-current="page" @endif
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-black text-slate-900">{{ $submittedRequest->statusLabel() }}</span>
                                        <span class="text-[11px] font-bold text-slate-500">{{ $submittedRequest->submitted_at?->format('Y/m/d H:i') }}</span>
                                    </div>
                                    <div class="mt-1 truncate text-xs font-bold text-slate-500">
                                        {{ data_get($submittedRequest->form_data, 'one_line') ?: 'キャラクター像未入力' }}
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>

                    <div class="flex flex-col gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-xs font-black text-violet-600">現在の進行状態</div>
                            <div class="mt-1 text-lg font-black text-violet-950">{{ $designRequest->statusLabel() }}</div>
                        </div>
                        <div class="text-xs font-bold text-violet-700">
                            提出日 {{ $designRequest->submitted_at?->format('Y/m/d H:i') }}
                        </div>
                    </div>

                    <details class="rounded-xl border border-slate-200 bg-white">
                        <summary class="cursor-pointer list-none px-4 py-4 text-base font-black text-slate-900">提出したヒアリング内容を確認</summary>
                        <dl class="grid gap-0 border-t border-slate-100 sm:grid-cols-[12rem_minmax(0,1fr)]">
                            @foreach(config('character_icon_design.display_fields', []) as $field)
                                @continue($field['key'] === 'usage_scenes')
                                @php
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
                                @endphp
                                @if(filled($displayValue))
                                    <dt class="border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs font-black text-slate-500">{{ $field['label'] }}</dt>
                                    <dd class="whitespace-pre-wrap border-b border-slate-100 px-4 py-3 text-sm font-bold leading-6 text-slate-800">{{ $displayValue }}</dd>
                                @endif
                            @endforeach
                        </dl>
                    </details>

                    @if($designRequest->status !== 'completed')
                        <a href="{{ route('character-icon-design.show', ['request' => $designRequest->id, 'edit' => 1]) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-violet-300 bg-white px-5 text-sm font-black text-violet-800 hover:bg-violet-50 sm:w-auto">
                            ヒアリング内容を修正
                        </a>
                    @endif

                    <section id="design-chat" class="rounded-xl border border-indigo-200 bg-slate-50 p-3 sm:p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-black tracking-wider text-indigo-500">PRIVATE DESIGN CHAT</p>
                                <h2 class="mt-1 text-lg font-black text-slate-950">管理人との専用チャット</h2>
                            </div>
                            <div class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-black text-indigo-700">非公開</div>
                        </div>
                        <p class="mt-2 text-xs font-bold leading-6 text-slate-500">候補画像を見ながら、残したい部分や直したい部分を相談できます。</p>

                        <div class="mt-4 space-y-3">
                            @forelse($designRequest->messages as $message)
                                <article class="flex {{ $message->sender_type === 'player' ? 'justify-end' : 'justify-start' }}">
                                    <div class="w-full max-w-2xl rounded-2xl border px-4 py-3 shadow-sm {{ $message->sender_type === 'player' ? 'border-violet-200 bg-violet-100' : 'border-slate-200 bg-white' }}">
                                        <div class="flex items-center justify-between gap-3 text-xs font-black">
                                            <span class="{{ $message->sender_type === 'player' ? 'text-violet-800' : 'text-slate-700' }}">
                                                {{ $message->sender_type === 'player' ? $character->name : '管理人' }}
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
                                                        <a href="{{ route('character-icon-design.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="group block overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                                                            <img src="{{ route('character-icon-design.attachments.show', $attachment) }}" alt="{{ $loop->iteration }}番：{{ $attachment->original_name }}" class="aspect-square w-full object-cover transition group-hover:scale-[1.02]">
                                                        </a>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm font-bold text-slate-500">
                                    管理人がヒアリング内容を確認しています。連絡が届くまでお待ちください。
                                </div>
                            @endforelse
                        </div>

                        @if($designRequest->status === 'completed')
                            <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">
                                キャラアイコン制作は完了しています。必要な連絡は引き続きこのチャットで行えます。
                            </div>
                        @endif
                        <form method="POST" action="{{ route('character-icon-design.messages.store', $designRequest) }}" enctype="multipart/form-data" class="mt-5 space-y-3 rounded-xl border border-slate-200 bg-white p-3 sm:p-4" data-submit-lock data-loading-text="送信中...">
                            @csrf
                            <label class="block">
                                <span class="text-sm font-black text-slate-800">管理人へのメッセージ</span>
                                <textarea name="body" rows="4" maxlength="3000" placeholder="例：2番の髪型を残して、衣装をもう少し青くしたいです。" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold leading-6">{{ old('body') }}</textarea>
                                @error('body')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                            </label>
                            <x-multi-image-picker label="参考画像" />
                            @error('attachments')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                            @error('attachments.*')<span class="mt-1 block text-xs font-bold text-rose-600">{{ $message }}</span>@enderror
                            <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-slate-950 px-5 text-sm font-black text-white hover:bg-slate-800 sm:w-auto">
                                専用チャットへ送信
                            </button>
                        </form>
                    </section>
                </div>
            @else
                <div class="p-3 sm:p-6">
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center">
                        <p class="text-sm font-black text-slate-700">提出済みのヒアリングシートはまだありません。</p>
                        <a href="{{ route('character-icon-design.show', ['view' => 'new']) }}" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-700 px-5 text-sm font-black text-white hover:bg-violet-800">
                            新規作成を始める
                        </a>
                    </div>
                </div>
            @endif
        </section>
    </div>
</x-layouts.facility>
