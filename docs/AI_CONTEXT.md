# AI_CONTEXT.md

Purpose: compressed current-state snapshot for ChatGPT and Codex.
Source of truth: current behavior = code / intended spec = DOMAIN_RULES.md + human rulings (see AGENTS.md "Source of truth"). On conflict, report 要裁定 — do not pick a side.
Last updated: 2026-07-29
Branch: main

## Read order

For implementation planning:
1. AGENTS.md
2. docs/AI_CONTEXT.md
3. docs/CODEMAP.md
4. docs/FEATURE_STATUS.md
5. docs/DATA_MODEL.md if DB/types are involved
6. docs/DOMAIN_RULES.md if game rules/economy/progression are involved
7. docs/dev-os/ for task templates, QA checklists, and impact maps

## Status legend

D = implemented
P = partially implemented
N = not implemented
? = unverified
X = deprecated/removed

## Stack

- App: Laravel 11 (PHP) + Livewire v3 + Blade + Alpine.js
- Styling: Tailwind CSS
- DB: MySQL (production on Xserver)
- Auth: Google OAuth, 1 account = 1 character。ゲストプレイ中は共通ヘッダの案内から同じユーザーIDへGoogle連携でき、進行データを引き継げる
- Payment: Stripe (輝石 purchase; paid/free tracked separately)。ゲストは購入不可で、Google連携またはメールアドレス・パスワード登録済みアカウントだけが購入できる
- Tests: PHPUnit via `php artisan test`
- Deploy: `php local_deploy.php` / `server_deploy_api.php` は移行期間のフォールバックとして残す。GitHubホステッドRunnerからXserverへの直接SSHは接続拒否を確認済み。標準経路はGitHub側でビルドし、このPCのリポジトリ専用WindowsセルフホストRunnerがSSH転送と原子的切替だけを行う構成で、ステージングの初回リリースと公開確認（`/` 200、未ログイン `/home` 302）は成功済み。本番SSHリリースは未実行。手順は `docs/GITHUB_ACTIONS_DEPLOY.md` を正とする。

## Product summary

ヴァルゼリアの冒険者 is a browser fantasy RPG recreating the feel of classic CGI-game FFA.
Core loop: login → explore/battle → EXP·Gold → level up → equip → job change → unlock next city → climb rankings.
Level cap: Lv255. Player-facing stat labels: HP / SP / 攻撃 / 防御 / 魔力 / 精神 / 敏捷 / 運 (old internal names like mp/str/agi and ATK/DEF/MAG/SPR/SPD/LUK remain internal only).
Primary currencies: Gold (in-game), 輝石 (paid/support currency).
Main player entities: character (1 per user), jobs (rank ★1-10), equipment (with 銘 affixes), materials, valmon (companion), monster marks, arena rankings, tavern NPCs.
World: 10 cities (アークレア→…→ヴァルゼリア城), 40+ dungeons (area 1-70 normal, 71-74 special, 75-83 街道).

## Current feature map

See docs/FEATURE_STATUS.md (single source for feature status; do not duplicate the table here).

## Architecture notes

- Routing: routes/web.php; screens are Livewire components + Blade views
- 冒険者カードは `AdventurerCardModal` の軽量Livewireインスタンスが表示イベントを受け、共通 `CityHeader` の能力値・通知・街情報を再描画しない。カードの表示・閉じる・職業階層取得は renderless action とし、既にある巨大なカードHTMLを応答ごとに再送・再描画せず、状態データだけを同期する。クリック直後はクライアント側で読み込み表示を開き、職業バッジは圧縮した配列で受け取り、選択された階層だけ小さなLivewire更新で詳細データ・DOM・画像へ展開する。
- Server pattern: thin Controllers, logic in app/Services/* (BattleService, ExplorationService, etc.)
- State management: Livewire component state + DB; no SPA framework
- P1の認証・送信・設定変更UIは、通常フォームでは明示した `data-submit-lock` を `resources/js/app.js` が処理し、Livewireでは対象アクションの `wire:loading` で完了まで再押下を止める。全ボタンには `resources/css/app.css` の短い押下変形があり、数量増減・タブ切替など連続操作前提の操作には送信ロックを自動適用しない。
- メイン画面内のタブ導線は、`MainScreenShell` が現在タブと探索退出処理を管理する。街・探索・冒険者・市場・闘技場の重い `MainScreen` は初期タブの表示後に1画面ずつ事前読込し、その後はDOMへ保持してAlpineで即時切替する。冒険者カードの読込中は次の事前読込を待機し、カード表示を優先する。MAP・設定・メッセージは必要時だけ読み込む。保持中のパネルは60秒経過後の再表示時にバックグラウンド更新し、探索退出時は探索パネルを即時無効化する。
- ホームの週間番付は新規表示では`AdventurerCardModal`へ直接イベントを送り、公開前から開かれている旧画面の`wire:click`だけは`StarTreeTowerRankingWidget::openWeeklyWinPlayerModal()`が互換処理として受ける。
- 闘技場タブは `ArenaNpcRankingService::screenEntries()` でTOP5と挑戦候補3件だけを取得し、画面表示時に全ランキングの戦力を組み立てない。同一リクエスト内の順位整合性確認と倉庫集計も各1回にまとめる。
- DB access: Eloquent models (snake_case columns); DTO/BattleActor use camelCase
- Auth/session: Google OAuth login, guest session is linkable to Google from the shared header, character via Auth::user()->characters()->first()
- Logging: battle_logs, player_lifecycle_events, gold_transactions, kiseki_transactions, admin_item_grant_logs, public_logs (bottom chat), admin analytics screens
- Battle: server-side auto turn-based; PRG pattern (redirect after POST); 3s cooldown via last_battle_at
- Player equipment performance: weapon, armor, and accessory fixed stats and legacy stored affix stats are migration-scaled once to version 2 (×8), except HP is corrected to ×4. Dynamically calculated engraving HP also uses the ×4 scale (rounding up), while its other stats remain ×8. Weapon STR/MAG use `round(B × (0.80 + W / 2400))`; armor DEF/SPR use `B + max(floor(W / 8), round(B × W / 2400))`, so they never fall below the pre-scale direct addition. Accessories add their fixed values directly; their non-HP enhancement result is kept at exactly ×8.
- Equipment proficiency penalty: production enables `EQUIPMENT_PROFICIENCY_PENALTY_ENABLED`, so all jobs may equip weapons and armor. Non-proficient weapons apply a category rate to base performance, + enhancement, engravings, and species killer damage (拳甲・刀85%、弓・斧・短剣75%、槍70%、剣・銃・杖・魔導具65%). Non-proficient armor applies `EQUIPMENT_NON_PROFICIENT_EFFECT_RATE` (65%) to base performance, + enhancement, engravings, and species resistance.

## Important invariants

- Do not change economy balance without explicit request.
- Do not change DB schema without migration/type update.
- Do not expose admin-only data to normal users.
- Public logs must not leak private/internal data.
- Feature status must reflect code, not intention.

## Known gaps / 未確認

- docs/FEATURE_STATUS.md is not yet synced against actual code (many rows unverified)
- 街の復興機能は未実装（復興予定素材9種のみ図鑑掲載済み）
- 厳密なログイン履歴は `player_lifecycle_events` で計測開始後の登録者から取得する。計測開始前の既存ユーザーについては過去行動を補完しない。
- 転職条件は2026-07-02裁定済み: 正仕様は「Lv30以上+要求職のマスター」（現実装どおり）。valzeria_specの「Lv100」は未採用案であり、コードへ反映しない

## Recent implementation state

詳細は docs/AI_CONTEXT_ARCHIVE.md（2026-07-02移設・全50項目）。恒久ルールは docs/DOMAIN_RULES.md が正。
該当機能に触るタスクではアーカイブの該当項目を検索して読むこと。ここには「隠し/停止中フラグ」と「最近の要点」だけを残す。

Hidden / disabled（承認なしに有効化しない）:
- 高位職ID60〜99は未公開（ID44〜49の新上級職は公開済み。超級職ID50〜59は条件達成者にのみ神殿表示。ID39〜43は職業IDとして欠番）
- 冒険者支援パス30日は `SUPPORT_PASS_ENABLED=false` で非公開（管理画面からON可）。購入時は即時発動せず30日利用券が所持品に入り、使用時に初めて発動/延長する。公開時は補給商会で、100輝石・1キャラクター1回限りの「冒険者旅立ちセット」も販売し、支援パス30日利用券、探索力の薬3個、素材/装備倉庫拡張、限定カードフレームを一括付与する。未購入かつ鍛冶街グランベルグ到達前（`highest_city_id < 4`）の冒険者には、街ヘッダ直下でセットへの案内を表示する
- フェルディア地方は `FERDIA_REGION_ENABLED=false` 既定で非公開（管理画面の追加コンテンツ設定からON可）
- 素材交換所では、薬草の若葉5個とアークレアの粗素材2個から薬草1個、または世界樹の葉片1個と妖精粉3個から薬草2個を調合できる。獣牙3個と魔物の欠片2個から回復薬1個、魔鉱片3個と魔物の欠片2個から魔力水1個も調合できる。毎日10個の無料補給は維持する
- 冒険者協会の寄付・ランク別救助費軽減は停止中（DOMAIN_RULES参照）
- S→SS装飾品進化は未実装表示。古代片は敵ドロップに加え、フェルディア13探索地の輝く宝箱から1宝箱1.0%で1個追加抽選する
- 薬屋の探索補助品16種はすべて1回の調合で1個完成する。フェルディアの輝く宝箱は通常素材2〜4個のうち1枠を地域代表素材、残りを地域内の通常素材とし、古代片1.0%は別枠で維持する

Recent key points:
- 敵固有ドロップの武器18種は、それぞれEPICまで既存武器へ合流しない専用一本道で進化する。通常14種は開始ランクF〜B、フェルディア4種はヒル・ホーク、ルイン・ギア、聖城の光霊、スノー・インプから0.03%で落ちる開始ランクS武器とする。進化先112武器は元武器の能力配分を武器ランク倍率で伸ばし、同武器種の現行進化素材を使う。全段階で個別の武器画像を表示し、フェルディア4種は武器マスタに固定された12%の飛行・機械・竜・悪魔特攻を進化後も保持する。`DROP_WPN_*`はランクを持っても`DropService`の汎用抽選から明示除外し、敵別`enemy_drops`だけで入手する。元武器の銘・個体特攻・品質・強化値は既存の進化処理どおり引き継ぐ。
- 武器マスタの固有特攻は通常の個体特攻と併存し、同じ種族なら加算してPvE直接ダメージへ1回適用する。合計上限は55%。固有特攻だけを持つ武器も装備市場へ出品できるが、ランダム特性の査定額には加えない。
- Lv40以上の相棒ヴァルモンは、牧場で個体ごとに絆技の発動スタイルと掛け声を選ぶ。均衡は5%・通常攻撃相当30%、Lv60の速攻は6%・25%、Lv80の豪撃は3%・50%。追撃ダメージは各スタイルの表示威力を基準に70〜130%で変動し、掛け声4種は性能差なし。通常・亜域・地図・ボスの対モンスター戦で最初のプレイヤー行動後に1戦1回だけ抽選し、初撃撃破時も勝敗確定前に専用技名・掛け声・情景文と「相棒による追撃」の表示を出す。PvP・チャンプ戦・星樹の塔は対象外。
- 新規キャラクターは1,000Gで開始する。`characters.wins + characters.losses` で数える累計100戦目までは、通常探索・10回探索・探索の地図で敗北しても、所持Gold・探索中の戦利品・ヴァルモンの卵を失わない。保護中は救助アイテムを消費せず、戦闘結果に「駆け出しの加護」を表示する。101戦目以降は既存の敗北ロストへ戻る。
- 生成バージョン6以降の新規探索地図は25%で単一種族地図を抽選する。特攻・耐性が設定された12種から、同じ地図Lv・戦力帯で異なる元モンスターを4体以上確保できる種族だけを選び、候補不足時は従来の混成地図にする。単一種族地図では「周辺の様子」を種族固有のフレーバーテキストへ置き換え、生成バージョン5以前の既存地図には遡及しない。
- 探索の地図は通常探索・通常ボス勝利で低確率に入手する個別コンテンツ。地図院で即時調査・公開し、新規公開地図は12時間または探索回数0まで共有探索できる。公開中は発見者ごとに3件までで、地図院一覧と公開前の詳細で現在の公開枠を確認できる。未調査・調査済み地図は破棄でき（調査費は返金しない）、公開中の本人地図は詳細から取り下げてすぐに枠を空けられる。取り下げ後は新規入場・再公開を止め、すでに開始された探索と確定済みの収益履歴は保持する。終了・取り下げ地図は6時間だけ終了印付きで表示する。入場料は街から入るたび1回だけで、入場中の×1/×10探索に追加料金はない。敵Lv・危険度・目安戦力・報酬傾向を確認でき、探索地種別に対応した背景画像をカードへ表示する。地図内で敗北した場合も通常探索と同じく、所持Goldの10%と入場後に得た素材・未装備／未保護の装備の50%を失い、探索中のヴァルモンの卵も失う。×10探索は最初の敗北または時間切れ敗北で停止してロストを1回だけ適用し、探索力が10未満なら残量分だけ実行して未実行の地図回数を返却する。探索予約は公開地図行ロックとリクエストUUIDで重複を防ぎ、5分以上未実行の予約は次回予約時に未消費回数と未実行時の入場料を回収する。新規地図の報酬傾向は8種類で、既存7傾向を各13%、古代片傾向を9%で抽選する。古代片傾向は通常敵Lv142以上となり、有効な古代片7種から地図ごとに固定した1種を表示して勝利ごとに0.38%で追加抽選する。既存の通常報酬フォールバック地図も通常敵Lv142以上なら同じ固定古代片抽選を行い、Lv141以下は報酬傾向を表示しない。地図を見つけた場合は通常探索・10回探索とも戦闘結果の獲得報酬に表示する。地図内では通常戦と共通の戦闘結果・ヴァルモン卵抽選を使うが、モンスター印は落とさない。管理者は `/admin/published-maps` で現在入場可能な地図の発見者・公開条件・敵・報酬詳細を確認できる。地図院は7月25日23:59まで街の施設一覧上部にPickup表示する。
- 探索の地図の新規生成時の探索可能回数は、通常300〜600回・希少600〜900回・英雄900〜1,200回・伝説1,200〜1,500回。修練の導きは通常・希少が約1/3、英雄が2/7（約260〜340回）、伝説が1/4（300〜380回）となり、Job EXPは順に最大6/7/8。生成バージョン5以降の英雄・伝説地図は等級別に報酬傾向を強化し、勝利ごとに英雄0.15%・伝説0.35%の限定報酬枠も独立抽選する。生成バージョン4以前の地図には遡及しない。通常敵・精鋭敵・通常ボス・地図内の敵からの地図ドロップ率は基礎0.5%に統一し、実装記念ボーナスは終了した。地図内の敵は地図用の名称で表示しつつ、戦闘結果では選ばれた元モンスターの画像を表示する。
- PvEの敵→プレイヤー直接攻撃は、割合軽減式を既定で有効にしている。物理は `敵ATK² ÷ (敵ATK + 3.5 × プレイヤーDEF)`、魔法は `敵MAG² ÷ (敵MAG + 3.5 × プレイヤーSPR)`。会心は先にDEF/SPRを半減してから既存の1.5倍補正を掛ける。通常・強敵・レア・ボス・秘境・隠しボス・星樹の塔・敵技の直接攻撃が対象で、プレイヤー側攻撃、継続/反射/固定/割合ダメージ、PvP・ランク戦・チャンプ戦は対象外。環境変数を `false` にすると従来の減算式へ戻せる
- 追加ダンジョンは `region_depth_dungeons` マスタと `RegionDepthDungeonService` により管理する。街の探索一覧ではストーリーの下に暗色カードで表示し、黒炉深坑は危険度・連戦数・最高到達記録を通常探索の深度と分離して保持する。追加ダンジョンではモンスター印を抽選しない。
- 管理画面の `/admin/security-anomalies` は、10分で5,000戦以上の大量戦闘、Gold/輝石異常、通常報酬で3超・探索地図で8超のJob EXP、同一IP大量アカウント、装備・素材急増、管理者付与後の高額取引を5分ごとにルール検知する。案件は検知・確認中・問題なし・措置済みで管理し、状態変更者・日時・メモを履歴化する。ログインIPは平文保存せず、HMACハッシュとマスク表示だけを使い、元になるログイン観測レコードを90日保持する。観測は認証時と通常ページ表示時に行い、Livewireのタブ切り替え・pollingはログインとして重複記録しない。検知からの自動停止・自動回収は行わない。
- ユーザー個別調査は、初期表示で `player_lifecycle_events` の計測開始後ログインを最終記録日順に最大60件、冒険者アイコンとキャラクター名を大きく表示する3列カードで表示し、選択後に従来の個別調査を開く。アカウント表示名（`users.name`）はカードに出さない。カードからは対象キャラクターを選んだ状態の輝石付与・プレイヤー調整、送信・受信を含む対象者だけの公開ログ管理へ移動できる。選択した冒険者へは、不具合報告の有無にかかわらず管理人名義の個別メッセージを送信でき、同じ画面で返信履歴を確認する。ログインは日ごとに重複を抑止して記録するため、同一日内の再ログイン時刻までは区別しない。
- 管理画面共通ヘッダは、管理人スレッドで冒険者の返信が最新になっている人数を1分ごとに確認し、ログアウト横の通知ベルとユーザー調査メニューへ未対応件数を表示する。ベルは最新の返信者を選択したユーザー個別調査へ移動し、管理人が同じスレッドへ送信すると未対応から外れる。問い合わせメール件数との合計は既存のfavicon・画面タイトルにも表示する。
- 地下の謎の穴は開拓度100でLv180の「深淵門の番人ヴェイルガード」に挑戦できる。火傷・回復阻害・予兆つき強攻撃を使い、撃破で同地点を踏破扱いにするが、アビスヴェイル本体は未実装で解放しない。
- 「市場・依頼」タブには素材市場・装備市場・調達依頼を別カードで表示する。素材市場は出品時の手数料がなく、冒険者出品が成立した時にだけ販売額の5%をGold sinkとして回収する。素材市場とは別に、装備市場では銘または特攻付き武器を個体単位で売買する（2026-07-13裁定：匿名売買を廃止し出品者名を表示）。`EquipmentMarketService` が行ロックとGoldService経由の支払い・受取を管理し、販売額の10%をGold sinkとして回収する。出品は査定額の50〜250%、72時間で期限切れ、購入後は72時間再出品できず、進化後にも制限を引き継ぐ。出品中の武器は装備・売却・強化・進化・ヴァルモンの餌消費ができず、餌候補にも表示しない。査定v2は装備本体へだけ品質・強化倍率を掛け、特性はI〜Vを5,000/25,000/150,000/450,000/1,200,000Gで評価し、2特性なら高い方100%・低い方60%で加算する。出品時に本体/特性/総額と査定バージョンを保存し、既存出品の価格・旧査定は変更しない。装備市場の出品・購入画面では、ランク/武器種/強化値・出品者名を日本語ラベルで表示し、武具の基本性能・銘の性能・種族特攻の能力上昇値はカード分けせず色分けバッジで一覧表示する。出品価格は査定範囲内で変更できることを入力欄の近くに明記する。
- 銘・武器の種族特攻・防具の種族耐性は段階I〜Vを持つ。品質倍率は通常品1.00/良品1.15/逸品1.35、銘の基礎性能補正はI〜Vで8/16/24/32/40%、武器の種族特攻は6/12/18/24/30%、防具の種族耐性は5/10/15/20/25%に品質倍率を掛ける。耐性は最大35%まで、対応種族から受けるPvE直接ダメージの最終値を軽減する。銘は強化後の装備の最も高い基礎能力値を基準にし、生命の銘は算出値の3倍をHPへ加える。全能力を上げる調律の銘は、各能力の算出値を単能力銘の55%とし、防具ではHPを6倍、武器では3倍へ加える。鍛冶屋の統合画面は武器・防具の銘と、武器の特攻／防具の耐性を鍛錬・移しできる。鍛錬は武器同士または防具同士に限り、同じ装備種・同じ特性・同じ段階なら段階を上げ、それ以外は対象特性だけを移す。
- 装備強化はランク上限制（G〜E=+10、D〜B=+15、A=+20、S=+25、SS〜EPIC=+30）。武器・防具は+5まで各3%、以降は2%/1.5%/1.2%/1%/0.8%へ逓減して+30で合計47.5%上昇する。装飾品は元の非ゼロ能力値比率を保つ総量配分制で、単能力型のSS/SSS/EPICは+30時の正の能力値合計を200/300/400にする。ATK・DEF・SPD・MAG・SPR・LUKをすべて持つ全能力型は、単能力型目標の半分を各能力へ配分し、+30時に各100/150/200にする。G〜Sは既存の追加値曲線を維持し、Sの上限+25では合計+25を加算する。進化では選択した進化元個体の+値を進化先上限まで引き継ぐ。+6以降は石・高純度石・街素材・精錬核を段階的に消費し、成功率は100%。SSSのGoldは+1〜+10で30万G、+11〜+20で120万G、+21〜+30で350万G、合計500万Gの専用段階表を使う。市場の装備本体査定も+30まで同じ性能倍率を反映する。
- 職業階層は8層（normal〜myth、EXP倍率1/2/5/8/10/15/22/30、転職引き継ぎ1/2・2/5・1/3）。北境の霊峰エルヴァンの最終ボスを倒すと冠位の証が撃破記録へ刻まれ、冠位職が神殿に表示される。Lv30以上かつ未使用BPなしなら超級職のマスターを問わず転職できる。冠位の証は素材・所持品・素材報酬として扱わない。ヴァルゼリア大陸の敵マスタは職業EXPを0〜3に収め、4以上を設定しない。職業EXPは各敵・モードの報酬設定または通常戦のLv差計算に従い、深度・亜域などの補正後も1回の報酬処理で最大3に制限する
- 武器・防具・装飾品の強化素材は到達強化値で固定し、ランク・現在地・`unlock_city_id`に依存しない。都市素材は強化値帯ごとに、+11〜+13は氷晶石/氷帝晶、+14〜+16は砂金石/砂王金晶、+17〜+20は魔導結晶/ルミナス魔晶、+21〜+23は瘴気の骨片/深魔骨核、+24〜+26は天空石/セレスティア星晶、+27〜+30は魔王城の黒晶/ヴァルゼリア黒核を使う。武器は強化石系、防具は同数の守護石系、装飾品は同数の調律石系を使い、都市素材・魔物の魔核・精錬核は共通にする。武器・防具・装飾品のGoldは+nに対して n×n×300G（+1=300G、+10=30,000G、+30=270,000G）。
- 星樹の塔は1〜100階マスタ、塔戦闘、戦闘後に通常探索に近い結果画面から次階へ進む導線、次階前の行動選択、50階以降5階ごとの挑戦中累積「星樹の構え」、一時中断/再開、軽量EXP/Job EXP報酬、行商人、行商人購入アイテムの塔内使用/次戦闘自動護符、ランキング、50/60/70/80/90/100階の公開ログ、10階刻み到達称号、50/70/90階初回到達の選択式武器宝箱、50階初回到達の冒険者カード背景自動付与（背景獲得の公開ログなし）、100階初回到達の冒険者カード装飾枠、エルフィアのダンジョン一覧からの導線、管理画面でのON/OFFと開催期間設定まで実装済み。ただし `STAR_TREE_TOWER_ENABLED=false` 既定で、管理設定がONかつ開催期間内の時だけダンジョン一覧に表示。汎用Gold/ランダムドロップ報酬は未実装
- フェルディア地方は `config/ferdia_world_map.php` と `FerdiaMapService` で本線13探索地・公開物語分岐4地点・アビス前段1地点＋3街のMAP状態を管理し、`extra_content.enabled.ferdia_unlocked` がONかつ期間内の時だけ街移動画面にタブ表示する。探索地は既存 `areas` / `character_area_progresses.development_point` を使い、フェルディアだけ勝利時開拓度が1〜2上がる。見晴らしの丘道・グランフォード外郭路・水門街道は開拓度150後の関門ボス撃破で次の街を解放し、北境の霊峰エルヴァンには最終ボスを置く。星詠みの廃塔・瀑布神殿アクエリス・風化列柱都市オルド・白潮灯台は本線の到達条件で必ず公開され、4地点すべての開拓度を最大にすると地下の謎の穴が恒久解放される。街はルヴァン、グランフォード、アーヴェンの3つを既存Cityとして登録し、街滞在時は通常の街施設を表示する。MAP上の探索地ボタンから探索タブへ移った場合だけ、街タブは「フェルディア簡易拠点」として主要施設を表示する。薬屋では50戦有効・同時1種の探索補助品を作れ、既存4種は1調合3個、冒険開始直後から調合できる誘魔香12種は1調合1個とする。誘魔香は通常探索で指定種族の出現重みを3倍にする。`ExplorationSupportService` は品目ごとの残数を保存するため、切り替え・解除後も再装備時に続きから使える。戦闘結果ではHP/SP直下に回復アイテム、その下に現在のもちものを表示し、下部モーダルで探索補助品を変更できる。使用中に別品へ切り替える場合は、残り戦数の保存と予備消費の有無を確認してから実行する。探索補助品は独立した追加コンテンツ `exploration_support` を既定ONとし、新規冒険者も最初の街から薬屋・もちものを利用できる。運営設定でOFFにした場合はデータを残したまま効果を凍結する。
- 通常PvEの敵技は `enemy_actions` マスタで管理する。敵ターンごとに特徴行動を最大1回だけ抽選し、フェルディア敵には火傷・毒・出血・DEF低下・鈍足・回復阻害・現在HP割合・連続・溜め攻撃を設定済み。PvP・チャンプ戦・星樹の塔は対象外で、予兆技の自動防御作戦は将来拡張用のデータだけを保持する。
- 探索力上限は勝利数で250→350(1000勝)→450(2000勝)→500(3000勝)。探索力制はOFF初期・運営切替
- 輝石: 補給商会の小瓶は輝石10・薬は輝石25・各1日10個。補給商会商品は管理画面でキャンペーン価格と開始/終了日時を設定でき、期間中だけ割引価格になる。無償輝石は戦闘勝利0.1%・1日3個。管理画面から有償輝石付与可（監査ログ付き）。管理画面では選択キャラクターへのGold個別送付も理由必須で行え、Gold取引台帳と通知に記録する。通常プレイヤー全員へのお詫び配布では、探索力の小瓶/薬・個数・通知タイトル/本文を指定でき、管理者・検証用キャラクターを除外して監査ログ付きで一括付与する。同じ操作の再送では重複付与しない。7月登録キャンペーンの初回配布済み冒険者には、対象数が211名と一致する場合だけ、探索力の薬5個を一度だけ追加送付できる
- 番付の総資産は手持ち+銀行。宿屋は直近7日想定利益で参加。番付案内バナーは2026-07-14まで
- 街の番付掲示板は、累計勝利数番付を残したまま週間勝利数増分番付を先頭に表示する。全タブ共通の星樹の塔・闘技場ランキング枠でも「週間勝利」を初期タブとして表示し、本人の勝利数・順位・見込み報酬と上位5名から詳細番付へ進める。上位5名と詳細番付では、冒険者名から既存の冒険者カードを開ける。日本時間の月曜9:00以上〜翌月曜9:00未満（表示上は翌月曜8:59まで）の `battle_logs` にある実戦勝利（通常系の `win` と亜域の既存 `victory`、獲得EXPあり）を集計し、非戦闘イベント・時間切れを除外する。同勝利数は同順位、同率50位は全員入賞とする。月曜9:05に前週分を確定し、失敗時は同時刻の日次実行で冪等に再試行する。1/2/3/4〜10/11〜20/21〜30/31〜50位へ無償輝石20/15/10/8/5/3/2個、51位以下かつ10勝以上へ参加賞1個を自動付与する。参加賞は上位報酬へ加算しない。管理者・検証用は集計外、ゲストは表示のみで、確定時にGoogle連携またはメール登録済みの通常アカウントだけが報酬対象。付与は週・冒険者の一意記録、行ロック、単一transaction、`kiseki_transactions`、通知ベルで冪等化し、上位10位の能力なし名誉表示を翌週の冒険者カードへ出す。初回対象は2026年7月27日9:00開始シーズンで、それ以前の勝利は集計・報酬対象外とする
- ランク戦・チャンプ戦は挑戦者がボス戦セット奥義+職業固有必殺技を使用可（PvPは防衛側も）
- チャンプ戦は、画面表示時のチャンプIDと就任時刻を戦闘開始直前に再照合し、交代済みなら戦闘せず最新表示へ戻す。ホームのチャンプカードはタブ復帰時にも更新する。戦闘結果はキャラクター別の一時トークンでも保持し、ホームのLivewire更新と重なってセッション結果が失われても結果画面へ遷移する。撃破判定は挑戦者の行動でチャンプHPが0になった場合だけ成立し、挑戦者側の反動相打ちは撃破、チャンプ側の反動死は交代なしでHP1を維持する。
