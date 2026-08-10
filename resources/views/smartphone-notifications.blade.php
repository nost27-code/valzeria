<x-layouts.facility
    title="スマホ通知"
    subtitle="端末への通知と受け取る種類を設定します"
    headerIcon="🔔"
>
    @php
        $selectedTypes = collect(old('types', $enabledTypes))->map(fn ($type) => (string) $type)->all();
    @endphp

    <div class="mx-auto max-w-3xl space-y-4">
        @if (session('message'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                保存できませんでした。もう一度お試しください。
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-black text-[#003366]">まず、この端末で通知をONにする</h2>
            <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">
                スマホごとに1回ずつ設定が必要です。下のボタンを押したあと、スマホに表示される確認でも「許可」を選んでください。
            </p>

            <div class="mt-3">
                <x-web-push-control :detailed="true" :show-unavailable="true" />
            </div>

            <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2.5 text-xs font-bold leading-relaxed text-amber-900">
                このページで種類を保存しただけでは、端末通知はONになりません。上の表示が「新着を端末へ通知します」になっていることも確認してください。
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-black text-[#003366]">ホーム画面から開く必要があります</h2>
            <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">
                スマホ通知は、ブラウザで普通に開いただけでは設定できません。ヴァルゼリアをホーム画面に追加し、そのアイコンから開いてください。これをPWA（アプリのように使えるWebサイト）と呼びます。
            </p>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <h3 class="text-sm font-black text-slate-800">iPhone・iPad</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-xs font-bold leading-relaxed text-slate-600">
                        <li>iOS・iPadOS 16.4以降でSafariを開く</li>
                        <li>共有ボタンから「ホーム画面に追加」を選ぶ</li>
                        <li>表示される場合は「Webアプリとして開く」をONにして追加する</li>
                        <li>ホーム画面のヴァルゼリアのアイコンから開く</li>
                        <li>このページの「通知をON」を押し、通知を許可する</li>
                    </ol>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <h3 class="text-sm font-black text-slate-800">Android</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-xs font-bold leading-relaxed text-slate-600">
                        <li>Chromeでヴァルゼリアを開く</li>
                        <li>右上のメニューから「アプリをインストール」または「ホーム画面に追加」を選ぶ</li>
                        <li>ホーム画面のヴァルゼリアのアイコンから開く</li>
                        <li>このページの「通知をON」を押し、通知を許可する</li>
                    </ol>
                </div>
            </div>

            <ul class="mt-3 list-disc space-y-1 pl-5 text-[11px] font-bold leading-relaxed text-slate-500">
                <li>一度「許可しない」を選んだ場合は、スマホ本体の通知設定から許可し直してください。</li>
                <li>集中モード・おやすみモード・省電力設定により、通知が遅れたり表示されなかったりする場合があります。</li>
                <li>ブラウザのデータ削除やPWAの入れ直し後は、もう一度ONにする必要があります。</li>
                <li>ロック画面には通知ベルの見出しだけを短く表示し、詳しい本文は送信しません。</li>
            </ul>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-black text-[#003366]">受け取る通知の種類</h2>
            <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">
                ONの項目だけをスマホへ送ります。OFFにしても、ゲーム内の通知ベルにはすべて残ります。
            </p>

            <form method="POST" action="{{ route('smartphone-notifications.update') }}" class="mt-4 space-y-4" data-submit-lock data-loading-text="保存中...">
                @csrf
                @method('PATCH')

                @foreach ($catalog as $group)
                    <fieldset>
                        <legend class="mb-2 text-sm font-black text-slate-800">{{ $group['label'] }}</legend>
                        <div class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200">
                            @foreach ($group['items'] as $key => $option)
                                <label class="flex cursor-pointer items-center justify-between gap-3 bg-white px-3 py-3 transition hover:bg-slate-50">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-black text-slate-800">{{ $option['label'] }}</span>
                                        <span class="mt-0.5 block text-[11px] font-bold leading-relaxed text-slate-500">{{ $option['description'] }}</span>
                                    </span>
                                    <span class="relative inline-flex shrink-0">
                                        <input
                                            type="checkbox"
                                            name="types[]"
                                            value="{{ $key }}"
                                            @checked(in_array($key, $selectedTypes, true))
                                            class="peer sr-only"
                                        >
                                        <span class="h-7 w-12 rounded-full bg-slate-300 transition peer-checked:bg-sky-600 peer-focus-visible:ring-2 peer-focus-visible:ring-sky-500 peer-focus-visible:ring-offset-2"></span>
                                        <span class="pointer-events-none absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                <button type="submit" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#003366] px-4 text-sm font-black text-white shadow-md transition hover:bg-[#00264d] active:scale-[0.99] disabled:opacity-60">
                    通知の種類を保存する
                </button>
            </form>
        </section>
    </div>
</x-layouts.facility>
