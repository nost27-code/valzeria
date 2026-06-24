<x-layouts.facility title="案内所" headerIconImage="images/icon/icon_013.webp" bgImage="images/bg-castle.webp">
    <div class="mx-auto w-full max-w-[600px] px-3 pb-6 space-y-3">
        <p class="text-xs font-bold text-slate-500 leading-relaxed">
            項目をタップすると説明が表示されます。
        </p>

        @php
        $cooldowns = app(\App\Services\CooldownSettingService::class);
        $battleCooldownSeconds = $cooldowns->explorationBattleSeconds();
        $innCooldownSeconds = $cooldowns->innSeconds();

        $sections = [
            [
                'icon_image' => 'images/icon/icon_005.webp',
                'title' => 'ゲームの基本的な流れ',
                'body' => <<<'HTML'
<p>ヴァルゼリアの冒険者は、ブラウザで遊べる自動戦闘型のブラウザRPGです。</p>
<p>基本の流れはこのようになっています。</p>
<ol>
    <li>ログインし、キャラクターを選択または作成する</li>
    <li>探索先を選び、敵を倒して経験値・職業経験値・素材や装備を得る</li>
    <li>レベルアップやBP振り分けでキャラクターを強くする</li>
    <li>装備屋・ドロップ・鍛冶屋で装備を更新する</li>
    <li>職業を変えてさらに強くなる</li>
    <li>ボスを撃破して次の都市を解放し、より強いエリアへ進む</li>
    <li>ランキング上位を目指す</li>
</ol>
<p>操作の多くは「ダンジョンを選んで挑む」だけなので、忙しい方でも短時間でプレイできます。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_054.webp',
                'title' => 'キャラクターと成長',
                'body' => <<<'HTML'
<p>キャラクターには以下のステータスがあります。</p>
<ul>
    <li><strong>HP</strong>：戦闘中の体力。0になると敗北</li>
    <li><strong>SP（MP）</strong>：奥義（特殊スキル）を使うために消費する</li>
    <li><strong>STR（攻撃）</strong>：物理ダメージに影響</li>
    <li><strong>DEF（防御）</strong>：受けるダメージを軽減</li>
    <li><strong>MAG（魔力）</strong>：魔法ダメージに影響</li>
    <li><strong>SPR（精神）</strong>：魔法防御に影響</li>
    <li><strong>AGI（素早さ）</strong>：先制行動に影響</li>
    <li><strong>LUK（運）</strong>：クリティカルや特殊効果に影響</li>
</ul>
<p>敵を倒すと経験値が入り、一定量に達するとレベルアップします。<br>
レベルアップすると基礎ステータスが上昇し、BPも獲得できます。HP/SPは全回復せず、最大値を超えないように調整されます。<br>
装備や職業による補正もあるため、最終的な強さは「基礎値＋装備補正＋職業補正」で決まります。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_006.webp',
                'title' => '戦闘の仕組み',
                'body' => <<<HTML
<p>戦闘はサーバー側で自動的に処理されます。プレイヤーが操作するのは「どのダンジョンに挑むか」だけです。</p>
<p><strong>ターン制自動戦闘：</strong><br>
自分と敵が交互に攻撃するターン制で進みます。素早さ（AGI）の値が高いほど先に行動できます。</p>
<p><strong>奥義（ジョブアーツ）：</strong><br>
特定の職業・ランクで習得した奥義を最大3つセットできます。SP（MP）が十分にある場合、設定した方針にしたがって自動発動します。</p>
<p><strong>戦闘結果：</strong><br>
敵を倒すと勝利となり、経験値・職業経験値・素材・装備・まれなGoldや輝石を獲得できます。<br>
HPが0になると敗北です（詳しくは「敗北すると何が起こる？」を参照）。</p>
<p><strong>クールタイム：</strong><br>
戦闘後は約{$battleCooldownSeconds}秒間、次の戦闘を開始できません。連続で挑む場合も少し間隔を置いてください。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_045.webp',
                'title' => '敗北すると何が起こる？',
                'body' => <<<'HTML'
<p>敗北（HP0）すると、以下のペナルティが発生します。</p>
<ul>
    <li><strong>ゴールドの一部を失う</strong>：所持ゴールドが減少します</li>
    <li><strong>探索中に得た素材・装備の一部を失う</strong>：その探索で入手したものが失われることがあります</li>
    <li><strong>HPが大きく減少した状態で戻る</strong>：回復が必要です</li>
</ul>
<p>装備中・ロック中の装備は失いません。探索で得たものを多く抱えているときは、強すぎるダンジョンへの無謀な挑戦に注意しましょう。</p>
<p><strong>推奨レベルの確認を：</strong><br>
各ダンジョンには推奨レベルが設定されています。自分のレベルと大きく差がある場所では敗北リスクが高くなります。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_018.webp',
                'title' => '回復と宿屋の使い方',
                'body' => <<<HTML
<p>HP/SPが減った場合、街の「宿屋」で無料で全回復できます。</p>
<p><strong>宿屋のクールタイム：</strong><br>
宿屋を利用した後は、<strong>約{$innCooldownSeconds}秒間</strong>は次の行動（戦闘・宿屋利用など）ができません。「宿に泊まる」ボタンを押したらしばらく待ちましょう。</p>
<p><strong>その他の回復手段：</strong></p>
<ul>
    <li>補給所で毎日受け取れる回復アイテムを使う</li>
    <li>奥義や装備効果による回復を活用する</li>
</ul>
<p>宿屋を使うと探索待機が発生するため、すぐに続けたい場面では回復アイテムと使い分けましょう。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_007.webp',
                'title' => '装備の役割',
                'body' => <<<'HTML'
<p>キャラクターには武器・防具・アクセサリーを装備できます。装備のステータスが最終的な戦闘力に直結します。</p>
<p><strong>装備スロット：</strong></p>
<ul>
    <li>武器：攻撃力・魔力などに影響</li>
    <li>防具：防御・精神・HPなどに影響</li>
    <li>アクセサリー：特殊な効果やステータス補正</li>
</ul>
<p><strong>レアリティ：</strong><br>
装備にはレアリティがあり、高いほど強力です。レアリティが高いものはドロップ率が低く、または合成・強化で作成します。</p>
<p><strong>装備の入手方法：</strong></p>
<ul>
    <li>各都市の装備屋で購入</li>
    <li>敵からのドロップ</li>
    <li>鍛冶屋で素材を使って合成・強化・進化</li>
    <li>素材交換所や市場で必要素材を集める</li>
</ul>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_041.webp',
                'title' => '職業（ジョブ）の仕組み',
                'body' => <<<'HTML'
<p>職業（ジョブ）は、キャラクターの戦い方を決める重要な要素です。</p>
<p><strong>職業経験値とランク：</strong><br>
職業ごとに独立した経験値とランクがあります。特定のランクに達すると奥義（ジョブアーツ）を習得します。また、職業をマスターすると上位職の条件や継承奥義に関わります。</p>
<p><strong>転職のポイント：</strong></p>
<ul>
    <li>転職しても、キャラクターレベル・所持金・装備・これまでのジョブ経験値はすべて引き継ぎます</li>
    <li>過去にマスターした職業の奥義は「継承奥義」として引き続き使えます</li>
    <li>上位職・伝説職は、条件を満たすことで解放されます</li>
</ul>
<p><strong>奥義（ジョブアーツ）：</strong><br>
現在の職業で習得した奥義と、マスター済みの職業から継承した奥義を最大3つまでセットできます。奥義の発動はSPを消費します。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_003.webp',
                'title' => '新しいダンジョンの見つけ方',
                'body' => <<<'HTML'
<p>最初から全てのダンジョンが表示されているわけではありません。探索を進めることで新たなエリアを発見できます。</p>
<p><strong>発見の仕組み：</strong><br>
探索度を上げたり、ボスを倒したり、一定の条件を満たすと、そのエリアから繋がる新しい街道・ダンジョン・奥地への道が開きます。</p>
<p><strong>街道やボスを越えて都市を発見：</strong><br>
街道を進んだり、要所のボスを撃破したりすると、次の都市や新たな探索先を発見できます。</p>
<p>まずは現在見えている探索先を1つずつ進めて、世界を少しずつ開いていきましょう。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_004.webp',
                'title' => '探索度・危険度・開拓度の違い',
                'body' => <<<'HTML'
<p>各ダンジョン（エリア）には3つの指標があります。</p>
<dl>
    <dt>🔷 探索度</dt>
    <dd>そのエリアをどれだけ探索したかを示す値です。繰り返し挑戦することで上昇します。探索度が高いほど、エリア内の発見が充実していきます。</dd>
    <dt><img src="/images/icon/icon_046.webp" alt="" style="width:16px;height:16px;object-fit:contain;display:inline-block;vertical-align:middle;"> 危険度</dt>
    <dd>エリアの難易度や敵の強さを示す指標です。危険度が高いほど敵が手強く、敗北のリスクも上がります。一方で、高い危険度のエリアほど良い報酬が期待できます。</dd>
    <dt><img src="/images/icon/icon_004.webp" alt="" style="width:16px;height:16px;object-fit:contain;display:inline-block;vertical-align:middle;"> 開拓度</dt>
    <dd>そのエリアをどれだけ踏破したかを示す値です。開拓度が一定に達すると、新しい街道・街・探索先を発見できることがあります。</dd>
</dl>
<p>まずは危険度が低いエリアで探索度と開拓度を上げながら、徐々に危険なエリアに挑んでいくのがコツです。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_027.webp',
                'title' => '各種施設の使い方',
                'body' => <<<HTML
<p>各都市にはさまざまな施設があります。</p>
<p><strong>装備屋：</strong><br>
Goldで武器・防具を購入できます。都市ごとに品揃えが異なり、合成元になる低ランク装備や安定した通常装備を揃えられます。</p>
<p><strong>鍛冶屋：</strong><br>
素材を使って装備を合成したり、所持している装備を強化・進化させることができます。合成でしか入手できない装備も存在します。</p>
<p><strong>市場：</strong><br>
通常素材と地域素材を冒険者同士で匿名売買できます。装備品ではなく、合成や納品に必要な素材をやり取りする施設です。</p>
<p><strong>宿屋：</strong><br>
HP/SPを全回復します。利用後は約{$innCooldownSeconds}秒の探索待機があります（詳しくは「回復と宿屋の使い方」参照）。</p>
<p><strong>転職所（神殿）：</strong><br>
条件を満たした職業に転職できます。現在の職業ランクや奥義の習得状況も確認できます。</p>
<p><strong>ギルド：</strong><br>
依頼（NPCからの調達要求）を達成するとGoldなどの報酬を受け取れます。初心者ミッションは街画面の案内から確認できます。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_001.webp',
                'title' => '都市の解放と次の街へ',
                'body' => <<<'HTML'
<p>世界には複数の都市があり、最初は最初の街だけが利用できます。</p>
<p><strong>解放条件：</strong></p>
<ul>
    <li>街道や現在の都市周辺の探索先を進める</li>
    <li>要所のボスやダンジョンをクリアする</li>
    <li>必要なジョブ条件を満たしている</li>
</ul>
<p>条件を満たすと次の都市が解放され、移動できるようになります。</p>
<p><strong>街を移動すると：</strong></p>
<ul>
    <li>その都市のショップが利用できるようになる</li>
    <li>その都市周辺のダンジョンに挑める</li>
    <li>新しい職業が解放されることがある</li>
</ul>
<p>前の街にはいつでも戻れます。Goldや素材が足りないときは前の街で整えてから先に進みましょう。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_009.webp',
                'title' => 'チャンプバトルとは',
                'body' => <<<'HTML'
<p>チャンプバトルは、現在の「チャンピオン」に挑戦する特別な戦いです。</p>
<p><strong>チャンピオンとは：</strong><br>
チャンプバトルで最後に勝ち残ったプレイヤーのことです。トップページや画面上部に現在のチャンピオンが表示されます。</p>
<p><strong>チャンプバトルの流れ：</strong><br>
現在のチャンピオンに挑戦し、勝利するとあなたが新しいチャンピオンになれます。チャンピオンでい続けると特別な報酬や称号が得られることも。</p>
<p><strong>注意点：</strong><br>
チャンプバトルは強さが重要です。まずは装備・レベル・ジョブをしっかり整えてから挑みましょう。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_037.webp',
                'title' => 'ヴァルモンとは',
                'body' => <<<'HTML'
<p>ヴァルモンは、冒険の中で出会える不思議な生き物です。</p>
<p><strong>出会い方：</strong><br>
ダンジョン探索中に稀にヴァルモンの卵を入手できます。卵はしばらく時間が経つと孵化します。</p>
<p><strong>パートナー：</strong><br>
ヴァルモンを「パートナー」に設定すると、プロフィールや世界に表示されます。またパートナーにすることで、探索中に素材を見つけてくれることがあります。</p>
<p><strong>コレクション要素：</strong><br>
様々な種類のヴァルモンがおり、集めていくコレクション要素があります。レアなヴァルモンほど出会う機会は少ないです。</p>
HTML,
            ],
            [
                'icon_image' => 'images/icon/icon_010.webp',
                'title' => 'ランキングについて',
                'body' => <<<'HTML'
<p>ランキングはプレイヤー同士の強さを競う指標です。</p>
<p><strong>主なランキング：</strong></p>
<ul>
    <li><strong>レベルランキング</strong>：キャラクターのレベルが高いほど上位</li>
    <li><strong>その他の指標</strong>：討伐数・称号など、様々な観点で競えます</li>
</ul>
<p><strong>称号：</strong><br>
特定の条件（レベル達成、ボス討伐など）を満たすと称号が解放されます。称号はプロフィールに表示でき、実績の証になります。</p>
<p>ランキング上位を目指すには、効率よくレベルを上げ、強い装備を揃えることが重要です。</p>
HTML,
            ],
        ];
        @endphp

        <div class="space-y-2">
            @foreach($sections as $i => $section)
            <div x-data="{ open: false }" class="rounded-lg border border-slate-200 bg-white overflow-hidden shadow-sm">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition-colors"
                    :class="open ? 'bg-amber-50' : 'bg-white hover:bg-slate-50'"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        @if(!empty($section['icon_image']))
                            <img src="{{ asset($section['icon_image']) }}" alt="" class="shrink-0 w-5 h-5 object-contain">
                        @else
                            <span class="shrink-0 text-lg leading-none">{{ $section['icon'] ?? '' }}</span>
                        @endif
                        <span class="text-sm font-black text-slate-900">{{ $i + 1 }}. {{ $section['title'] }}</span>
                    </div>
                    <svg
                        class="shrink-0 h-4 w-4 text-slate-400 transition-transform duration-200"
                        :class="open ? 'rotate-180' : ''"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div
                    x-show="open"
                    x-transition:enter="transition-all duration-200 ease-out"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition-all duration-150 ease-in"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    class="border-t border-slate-100 bg-white px-4 py-4 help-body"
                >
                    {!! $section['body'] !!}
                </div>
            </div>
            @endforeach
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-4 text-sm font-bold text-amber-800">
            解決しない場合はトップページの「お問い合わせ」からご連絡ください。
        </div>
    </div>

    <style>
        .help-body { font-size: 13px; line-height: 1.85; color: #374151; }
        .help-body p { margin-bottom: 0.75rem; }
        .help-body p:last-child { margin-bottom: 0; }
        .help-body ul, .help-body ol { padding-left: 1.4rem; margin-bottom: 0.75rem; }
        .help-body li { margin-bottom: 0.3rem; }
        .help-body strong { font-weight: 800; color: #1e293b; }
        .help-body dl { margin-bottom: 0.75rem; }
        .help-body dt { font-weight: 800; color: #1e293b; margin-top: 0.6rem; margin-bottom: 0.15rem; }
        .help-body dd { margin-left: 1rem; margin-bottom: 0.4rem; color: #4b5563; }
    </style>
</x-layouts.facility>
