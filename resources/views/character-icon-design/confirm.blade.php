<x-layouts.facility title="キャラアイコン制作 内容確認" headerIcon="✦" bgImage="images/bg-castle.webp" :showGameHeader="true">
    @php
        $price = (int) $designRequest->price_kiseki;
        $formData = (array) $designRequest->form_data;
        $options = config('character_icon_design.options', []);
    @endphp

    <div class="mx-auto w-full max-w-4xl px-3 py-5 sm:px-6 sm:py-8">
        <section class="overflow-hidden rounded-2xl border border-violet-200 bg-white shadow-sm">
            <header class="bg-gradient-to-r from-violet-950 via-indigo-950 to-slate-950 px-4 py-5 text-white sm:px-6">
                <p class="text-xs font-black tracking-[0.2em] text-violet-200">REVIEW YOUR REQUEST</p>
                <h1 class="mt-2 text-2xl font-black">ヒアリング内容の確認</h1>
                <p class="mt-2 text-sm font-bold leading-7 text-violet-100">
                    入力内容を確認し、問題がなければ画面下部から提出してください。
                </p>
            </header>

            <div class="space-y-5 p-3 sm:p-6">
                <div class="grid grid-cols-3 gap-2 text-center text-xs font-black sm:text-sm">
                    <div class="rounded-lg bg-slate-100 px-2 py-3 text-slate-500">1. 入力</div>
                    <div class="rounded-lg bg-violet-700 px-2 py-3 text-white">2. 確認</div>
                    <div class="rounded-lg bg-slate-100 px-2 py-3 text-slate-500">3. 提出</div>
                </div>

                <div class="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:grid-cols-3">
                    <div>
                        <div class="text-xs font-black text-amber-700">プレイヤー名</div>
                        <div class="mt-1 text-lg font-black text-amber-950">{{ $character->name }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-black text-amber-700">支払う輝石</div>
                        <div class="mt-1 text-lg font-black text-amber-950">{{ number_format($price) }}輝石</div>
                    </div>
                    <div>
                        <div class="text-xs font-black text-amber-700">現在の所持輝石</div>
                        <div class="mt-1 text-lg font-black text-amber-950">{{ number_format($totalKiseki) }}輝石</div>
                    </div>
                </div>

                @if($totalKiseki < $price)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black leading-6 text-rose-800">
                        輝石が不足しています。入力内容は下書きとして保存されています。
                        <a href="{{ route('kiseki.shop') }}" class="ml-1 underline underline-offset-4">輝石ショップを見る</a>
                    </div>
                @endif

                <section class="overflow-hidden rounded-xl border border-slate-200">
                    <h2 class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-base font-black text-slate-900">
                        入力したヒアリング内容
                    </h2>
                    <dl class="grid gap-0 sm:grid-cols-[12rem_minmax(0,1fr)]">
                        @foreach(config('character_icon_design.display_fields', []) as $field)
                            @continue($field['key'] === 'usage_scenes')
                            @php
                                $rawValue = data_get($formData, $field['key']);
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
                                <dt class="border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs font-black text-slate-500">
                                    {{ $field['label'] }}
                                </dt>
                                <dd class="whitespace-pre-wrap border-b border-slate-100 px-4 py-3 text-sm font-bold leading-6 text-slate-800">{{ $displayValue }}</dd>
                            @endif
                        @endforeach
                    </dl>
                </section>

                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black leading-6 text-rose-800">
                    提出後はヒアリング内容を変更できません。修正が必要な場合は「入力内容を修正」から戻ってください。
                </div>

                <div class="sticky bottom-2 z-10 flex flex-col gap-2 rounded-xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur sm:static sm:flex-row sm:justify-end sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none">
                    <a href="{{ route('character-icon-design.show') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-black text-slate-700 hover:bg-slate-50">
                        入力内容を修正
                    </a>
                    <form method="POST" action="{{ route('character-icon-design.form.submit') }}" data-submit-lock data-loading-text="提出中...">
                        @csrf
                        <button type="submit" @disabled($totalKiseki < $price) class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-violet-700 px-5 text-sm font-black text-white shadow hover:bg-violet-800 disabled:cursor-not-allowed disabled:bg-slate-400 sm:w-auto">
                            {{ number_format($price) }}輝石を支払って提出
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</x-layouts.facility>
