<x-layouts.facility title="名もなき鍛冶屋" :show-facility-header="false" page-background-style="background:#171b20;color:#f8efd9" main-content-class="py-2 nw-intro-page" exit-text-class="nw-intro-exit" :exit-url="route('nameless-workshop.return', ['tab' => 'town'])" exit-label="街へ戻る">
    <style>
        .nw-intro-page .nw-intro-exit,.nw-intro-page > .mt-8 > a{background:#fff;color:#171b20;border:1px solid #dce1e6;border-radius:8px;min-height:48px}
        .nw-intro-page .nw-intro-exit{padding:10px 16px}
        .nw-intro-page > .mt-8 > a .animate-spin{color:inherit}
        .nw-intro-page .nw-intro-exit:hover,.nw-intro-page > .mt-8 > a:hover{background:#edf0f3;color:#171b20}
        .nw-intro-page .nw-intro-exit:focus-visible,.nw-intro-page > .mt-8 > a:focus-visible{outline:3px solid #f6d998;outline-offset:3px}
        .nw-intro{max-width:720px;margin:0 auto 24px;color:#f8efd9;line-height:1.8}
        .nw-intro *{box-sizing:border-box;min-width:0}.nw-intro [x-cloak]{display:none!important}
        .nw-intro .scene{position:relative;overflow:hidden;border-radius:16px;background:#171b20;border:1px solid #6b573b}
        .nw-intro .cut{position:relative;min-height:280px;display:grid;place-items:center;background:#111820;border-block:22px solid #101419}
        .nw-intro .cut img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.6}
        .nw-intro .cut-copy{position:relative;text-align:center;padding:32px 18px;text-shadow:0 2px 8px #000}
        .nw-intro .cut-copy p{margin:6px 0;color:#eee1c2}.nw-intro .cut-copy h1{margin:6px 0;font-size:25px;letter-spacing:.1em;color:#fff5dc}
        .nw-intro .talk{padding:22px;background:linear-gradient(135deg,#252d32,#171b20)}
        .nw-intro .speaker{display:flex;align-items:center;gap:16px;margin-bottom:16px}.nw-intro .speaker img{width:190px;height:210px;object-fit:contain;flex-shrink:0}
        .nw-intro .speaker small{color:#c7b48e}.nw-intro .speaker h2{margin:4px 0;font-size:20px;color:#f6d998}
        .nw-intro .dialogue{min-height:112px;padding:18px;border:1px solid #6b573b;border-radius:10px;background:#10171c;font-size:16px}.nw-intro .dialogue p{margin:0}
        .nw-intro .em-growth{color:#f6d998;font-weight:700}.nw-intro .em-relic{color:#8bd9e8;font-weight:700}
        .nw-intro .actions{padding:16px 22px;background:#111820;display:flex;justify-content:space-between;gap:12px}.nw-intro .advance{margin-left:auto}
        .nw-intro .received{margin:0;padding:14px 22px;background:#111820;color:#f6d998;border-bottom:1px solid #6b573b}
        .nw-intro .continue{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:10px 20px;border:1px solid #dce1e6;border-radius:8px;background:#fff;color:#171b20;font-weight:800;font-size:15px;text-decoration:none}.nw-intro .continue:hover{background:#edf0f3}.nw-intro .continue:focus-visible{outline:3px solid #f6d998;outline-offset:3px}
        .nw-intro button{min-height:48px;padding:10px 20px;border:1px solid #dce1e6;border-radius:8px;background:#fff;color:#171b20;font-weight:800;font-size:15px;cursor:pointer}.nw-intro button:hover:not(:disabled){background:#edf0f3}.nw-intro button:disabled{opacity:.5;cursor:default}
        .nw-intro button:focus-visible,.nw-intro input:focus-visible{outline:3px solid #f6d998;outline-offset:3px}
        .nw-intro .choice{padding:22px}.nw-intro .choice h2{margin:0 0 6px;color:#f6d998;font-size:21px}.nw-intro .choice p{margin:6px 0 16px;color:#d7ccb6}
        .nw-intro .weapons{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:16px 0;padding:0;border:0}.nw-intro legend{font-weight:700;margin-bottom:6px}
        .nw-intro .weapon{display:flex;align-items:center;gap:8px;min-height:72px;padding:10px;border:1px solid #6b573b;border-radius:8px;background:#242b30;cursor:pointer}.nw-intro .weapon:has(input:checked){border-color:#f6d998;background:#443a28}.nw-intro input{width:19px;height:19px;accent-color:#d4b574;flex-shrink:0}.nw-intro .weapon strong,.nw-intro .weapon small{display:block}.nw-intro .weapon small{font-size:12px;color:#d7ccb6}.nw-intro .receive{width:100%}.nw-intro .notice{padding:14px 18px;margin-bottom:14px;border:1px solid #da9c8f;border-radius:10px;background:#4b2825;color:#fff}
        .nw-intro .weapon span{flex:1;text-align:center}.nw-intro .weapon img{display:block;width:88px;height:88px;max-width:100%;object-fit:contain;margin:0 auto 6px}
        .nw-intro .weapon strong{font-size:14px;line-height:1.5}
        @media(max-width:480px){.nw-intro .weapons{grid-template-columns:repeat(2,minmax(0,1fr))}.nw-intro .talk,.nw-intro .choice{padding:16px}.nw-intro .speaker{gap:12px}.nw-intro .speaker img{width:144px;height:170px}.nw-intro .speaker h2{font-size:18px}.nw-intro .cut-copy h1{font-size:22px}.nw-intro .dialogue{padding:14px;font-size:15px}}
    </style>
    <noscript><style>.nw-intro:not([data-intro-ready]) [x-cloak]{display:block!important}.nw-intro:not([data-intro-ready]) [data-intro-next]{display:none}.nw-intro:not([data-intro-ready]) [data-intro-back]{display:none}</style></noscript>
    <div class="nw-intro" data-nameless-introduction @if($receivedEquipment) data-nameless-receipt-dialogue @endif x-data="{ step: {{ $receivedEquipment ? 5 : 0 }} }" x-init="$el.setAttribute('data-intro-ready', ''); setTimeout(() => { if (step === 0) step = 1 }, 1800)">
        @if(session('error'))<p class="notice" role="alert">{{ session('error') }}</p>@endif
        @if($errors->any())<p class="notice" role="alert">{{ $errors->first() }}</p>@endif
        <section class="scene">
            @if($receivedEquipment)
                <p class="received"><strong>{{ $receivedEquipment->displayName() }}</strong>を受け取りました。</p>
            @else
            <div class="cut" x-show="step === 0" data-nameless-intro-cut>
                <img src="{{ asset(\App\Services\NamelessTownService::IMAGE) }}" alt="遺跡のふもとの工房街" fetchpriority="high">
                <div class="cut-copy"><p>槌の音に導かれて</p><h1>{{ $namelessTown->name }}</h1><p>炉の灯りが、あなたを迎える。</p></div>
            </div>
            @endif
            <div class="talk" x-show="{{ $receivedEquipment ? 'step >= 1 && step <= 6 && step !== 4' : 'step >= 1 && step <= 3' }}" x-cloak data-nameless-intro-talk>
                <div class="speaker">
                    <img src="{{ asset('images/npc/gantz-illustration.webp') }}" alt="{{ $smith->npc_name }}" width="600" height="600">
                    <div><small>鍛冶屋</small><h2>{{ $smith->npc_name }}</h2></div>
                </div>
                <div class="dialogue" aria-live="polite" aria-atomic="true">
                    <p x-show="step === 1" @if($receivedEquipment) x-cloak @endif>「真の冒険者よ、よくこんな場所まで来たのう。わしはガンツ。ここで槌を振るっておる鍛冶屋じゃ。」</p>
                    <p x-show="step === 2" x-cloak>「この先の洞窟が、<strong class="em-relic">古い遺跡</strong>につながっておってな。そこで見つけたのが、この<strong class="em-growth">名もなき武器</strong>たちじゃ。」</p>
                    <p x-show="step === 3" x-cloak>「使う者を待っておったのかもしれんのう。必要なら、<strong class="em-growth">一つ持っていけ</strong>。おぬしの旅で育ててやるんじゃ。」</p>
                    @if($receivedEquipment)
                    <p x-show="step === 5">「どうやら、この武器には<strong class="em-growth">無限の力</strong>が秘められておるようじゃ。わしのところに持ってくれば、<strong class="em-growth">いつでも鍛えてやろう</strong>。」</p>
                    <p x-show="step === 6" x-cloak>「それと、この武器には遺跡で見つかる<strong class="em-relic">遺物を組み込める</strong>ようじゃ。わしがこの武器を隅々まで調べて、ようやくその仕組みを突き止めたんじゃ。その仕組みを応用して、お前さんの<strong class="em-growth">SSSやEPICの装備品</strong>も、<strong class="em-relic">遺物を組み込めるように改良してやろう</strong>。<br><br>武器も防具も装飾品も、遺物と一緒に持ってくるといい。わしが取り付けて、<strong class="em-growth">その力を引き出してやろう</strong>。」</p>
                    @endif
                </div>
            </div>
            <div class="actions" x-show="{{ $receivedEquipment ? 'step < 4 || step === 5' : 'step < 4' }}" data-intro-next>
                <button type="button" x-show="step > 0" x-cloak :disabled="step <= 1" @click="{{ $receivedEquipment ? 'step = step === 5 ? 3 : Math.max(1, step - 1)' : 'step = Math.max(1, step - 1)' }}" data-intro-back>戻る</button>
                <button type="button" class="advance" @click="{{ $receivedEquipment ? 'step = step === 3 ? 5 : Math.min(6, step + 1)' : 'step = Math.min(4, step + 1)' }}" x-text="step === 0 ? 'ガンツと話す' : ({{ $receivedEquipment ? 'false' : 'step === 3' }} ? '武器を選ぶ' : '次へ')">ガンツと話す</button>
            </div>
            @if($receivedEquipment)
            <div class="actions" x-show="step === 6" x-cloak data-nameless-receipt-continue>
                <button type="button" @click="step = 5" data-intro-back>戻る</button>
                <a class="continue" href="{{ $workshopUrl }}">工房を開く</a>
            </div>
            @else
            <section class="choice" x-show="step === 4" x-cloak data-nameless-starter-choice>
                <button type="button" @click="step = 3" data-intro-back>戻る</button>
                <h2>最初の一本を選ぶ</h2>
                <p>遺跡で見つかった名もなき武器。旅の相棒を一つ選びましょう。受け取った後も、<strong class="em-growth">形や名前を変えられます</strong>。</p>
                <form method="post" action="{{ route('nameless-workshop.act', 'claim') }}">
                    @csrf
                    <input type="hidden" name="request_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="kind" value="weapon">
                    <fieldset class="weapons"><legend>武器の種類</legend>
                        @foreach($weaponTypes as $type => $stat)
                            <label class="weapon"><input type="radio" name="type" value="{{ $type }}" required @checked(old('type') === $type)><span>@if($image = config('nameless_equipment_images.weapon.'.$type))<img src="{{ asset($image) }}" alt="" width="300" height="300" loading="lazy" decoding="async">@endif<strong>名もなき{{ $type }}</strong><small>{{ $stat['label'] }} +{{ \App\Services\NamelessEquipmentService::powerFor(0) }}</small></span></label>
                        @endforeach
                    </fieldset>
                    <button class="receive" @disabled($storageSummary['equipment_free'] === 0)>この武器を受け取る</button>
                </form>
            </section>
            @endif
        </section>
    </div>
</x-layouts.facility>
