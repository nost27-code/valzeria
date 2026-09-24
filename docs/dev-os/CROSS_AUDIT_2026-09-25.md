# 横断監査 — 2026-09-25

## 最新mainを基準にした公開候補

本番公開依頼を受け、`origin/main` = `3246b26524d3f78878768882a353fa1561826a93` から専用worktreeを作成し、承認済みの修正差分だけを再適用した。migration modeは`none`。

- 装備の所有権再確認・HP/SP上限、能力値キャッシュ、市場ロック順、宿屋の例外時キャッシュ破棄を反映。国家限定・個人宛て取引と最新の装備性能計算を維持する。
- 宿屋のtransaction、画面監視とセッション復元、装備図鑑・英雄試練の公開前検証はmainの既存対策を維持し、追加回帰テストで確認する。
- 風の護符テストはmain側の旧期待値568を現行1600へ変更し、Sランクの期待値448は維持する。ゲーム数値は変更しない。
- 元の作業ツリーだけにあるPvP telemetryテスト、Composerのverifyスクリプト、神速・逆刻の不明瞭な仕様文はmainへ持ち込まない。Composerの既存testへ時間制限解除だけを加える。
- この節以降の2,814件という検証件数は元の作業ツリーの記録であり、公開候補の検証は別途記録する。

### 公開候補の検証結果

- 変更対象の回帰・関連テストは **90件・1,025 assertionsすべて成功**。所有権移転後の古い装備要求、同じ装備の再送、HP/SP上限、地図連戦、宿屋の巻き戻し、監視と公開前検証を含む。
- 全体3,130件の初回実行は3,087件成功、29 failures・14 errors。Viteビルドを生成した後、失敗43件と関連90件の計133件を再実行し、115件成功、5 failures・13 errorsとなった。
- 残る18件は、変更前のmain（`3246b26524d3f78878768882a353fa1561826a93`）の独立したworktreeでも同じテスト名・例外型・理由で再現した。今回の修正で追加された失敗はないが、全体テストがすべて成功したという意味ではない。
- 既存失敗は、塔の解放条件を満たさないfixtureに起因する13件、および奥義・ホーム描画の静的期待値、フェルディア素材検証、国家レイド破損snapshotの検証、装飾品強化の旧期待値の計5件。今回の公開差分では変更しない。
- PHP構文（変更対象12ファイル）、Composer設定検証、`npm ci`・`npm run build`、差分の空白検証が成功。DB migration・マスタ更新は含まない。

PHP 8.4.22、SQLite `:memory:`、worktree固有の依存関係、生成したテスト用APP_KEYで検証した。公開候補のログは `storage/logs/cross-audit-release-full.xml` と `storage/logs/cross-audit-release-rerun.xml`、比較元は別worktreeの `storage/logs/cross-audit-baseline-failures.xml`。実HTTP同時操作、MariaDBの複数接続競合、ログイン済み実機UIは未確認。

## 修正の追記（2026-09-25）

以下の監査本文は**修正前の記録**として保存している。ユーザーの修正依頼に基づき、ローカル作業ツリーでA1〜A3・B1〜B4を修正した。既存の未コミット作業を維持し、対象箇所だけに差分を加えた。DBスキーマ・既存DBデータ・料金・報酬・成長量の変更と本番公開は行っていない。

| 対象 | 修正内容 |
|---|---|
| A1 | 装備・解除時にCharacter→CharacterItemの順でロック・再取得し、所有者・出品・装備条件を再確認。同枠の変更を直列化。市場の購入・取消・期限切れもCharacterを先にロックする順序へ統一 |
| A2 | レベル成長の各段階、職業ランク・マスター、手動・自動装備解除で能力値キャッシュを無効化 |
| A3 | 手動装備交換・解除後に最新最大HPと最大SPで現在値を制限。古いCharacterの残高・HPを保存しない |
| B1 | 宿屋でCharacterをロック・再取得し、支払い・回復・探索状態リセットを一つのtransactionで実行。途中失敗時は一緒に巻き戻す |
| B2・B4 | mainの既修正に合わせ、指定画面のmountと公開プロパティの受け渡しを修正。認証、選択キャラ、探索先、既存リクエスト属性を成功・例外時とも復元 |
| B3 | mainの装備図鑑・英雄試練の検証処理を対象箇所へ追加。必要テーブル、試練場、職業、必須職マスター条件を検証 |
| C1 | 補正率の上限（神速+60%・逆刻+40%）と相手最大HP基準のdamage cap不適用を別文で明記。戦闘式の変更なし |
| 既存テスト | 旧探索力移行テストの日時固定、風の護符SS+30の期待値を現行1600へ同期、各テスト開始時の能力値キャッシュ初期化。PvPの行動回数の厳しいassertionは維持し、同SPDのfixtureに対する検証であることを明記 |
| 検証コマンド | Composerのtest・verifyにprocess timeout解除を追加 |

正式な回帰テストは `tests/Feature/CrossAuditRegressionTest.php`。市場での実売買と古い装備・解除要求、装備の再送・交換、例外時の巻き戻し、職業成長、自動解除、監視セッションの復元、実際のホーム・探索Blade描画、公開前検証の正常／不足ケース、実BattleServiceによる地図2連戦を含む。

### 修正後の検証結果

| 検証 | 結果 |
|---|---|
| PHPUnit全体 | **2,814件すべて成功、56,113 assertions、約403秒**。前回失敗した3件も成功 |
| 追加の回帰テスト | 21件・87 assertions成功。監査時の10ケースを含む |
| 関連テストの先行確認 | 62件・790 assertions成功 |
| PHP構文 | 2,336ファイル成功 |
| 公開前チェック | `valzeria:validate-release-readiness --all` 成功。未対応コンテンツの誤検出を解消 |
| マスタ整合性 | 奥義説明・効果種別、ダンジョン参照とも成功 |
| Composer設定 | `composer validate --no-check-publish --no-check-lock` 成功 |
| 差分レビュー | 保存した修正前18ファイルとの照合・差分の空白検証を実施。アプリ9ファイルのハッシュが全体テスト中に変わっていないことを確認 |

全体テストはPHP 8.4.22、`APP_ENV=testing`、SQLiteの`:memory:`、PHPメモリ上限768Mで実行した。ログは `storage/logs/cross-audit-20260925-fixed-full.xml`、関連テストは `storage/logs/cross-audit-20260925-fixed-focused.xml`。マスタと公開前チェックはローカルDBに対する読取り検証。今回の変更はPHP・テスト・設定・文書のみで、フロントエンドの再ビルドは実施していない。Composer経由の全体実行は未実施。

実HTTPの同時操作・MariaDBの独立接続によるロック競合・実機UI・本番は未確認。所有権移転や残高更新を挟む決定的な処理順の再現と、SQLiteでのtransaction巻き戻しは確認済み。今回の修正差分は既存ファイル18件と追加テスト1件で、DB migrationの追加はない。

再確認コマンド:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
& C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64\php.exe -d memory_limit=768M vendor\bin\phpunit tests\Feature\CrossAuditRegressionTest.php
# 全体確認はテストファイルの引数を省略する。
```

## 結論と対象

ローカルコードで7種類の不具合を再現した。うち、装備操作の所有権再確認漏れ、能力値キャッシュの更新漏れ、装備解除時のSP上限処理漏れの3種類は、取得し直した `origin/main` にも原因となる実装が残っている。ほか4種類は `origin/main` に対策があり、ローカルへの取り込み漏れとして扱う。別途、仕様書の不明瞭な記述と既存テスト3件の失敗を確認した。

これは本番障害の発生件数ではない。本番コード・本番DB・実プレイヤーの発生状況は未確認。`origin/main` はコード比較のみで、そこでの再現テストは未実施。

- 調査対象: 現在の作業ツリー、`docs/DOMAIN_RULES.md`、関連サービス・Controller・Model・View・テスト・公開前検証。
- ローカルブランチ: `codex/job-art-prototype`、HEAD `a2f8a97580aec9bde43a98d90be332c1af8f59e9`。
- 比較対象: `git fetch origin` 後の `origin/main` = `3246b26524d3f78878768882a353fa1561826a93`。
- 開始時: 追跡済み変更345件、未追跡エントリ974件。HEADとorigin/mainはローカルのみ5コミット／origin/mainのみ494コミット。作業ツリーには後続実装も混在しているため、494件すべてが実装未反映という意味ではない。
- アプリの実装、ゲームルール、既存DBの変更、公開は行っていない。監査用テスト・検証ログ・本報告書を追加した。

優先度: P1＝所有データ・経済・公開や監視の正確性への重大な影響、P2＝条件付きの状態不整合。C1は文書整理事項。

| ID | 優先度 | 指摘 | 確認方法 | origin/mainとの区別 |
|---|---|---|---|---|
| A1 | P1 | 売買と古い装備操作が重なると、新所有者の同一枠へ2個装備できる | 古いModelと所有権移転の処理順を再現 | 原因実装が残る |
| A2 | P2 | 地図連続探索で成長後も前の能力値を使う／装備変更後もキャッシュが残る | 実戦闘2回を含む地図バッチとサービス検証 | 原因実装が残る |
| A3 | P2 | 装備解除後にSPが最大SPを超えて残る | 装備解除サービスで再現 | 原因実装が残る |
| B1 | P1 | 宿屋が古いGold残高を保存し、他操作の増減を上書きする | 銀行預入との処理順・例外を再現 | 対策あり、ローカルに未反映 |
| B2 | P1 | 正常なホームでもhealth probeが描画エラーになる | 実際のBlade描画 | 対策あり、ローカルに未反映 |
| B3 | P1 | 公開前チェックが既存コンテンツを未対応として拒否する | ローカルDBに対する検証コマンド | 対策あり、ローカルに未反映 |
| B4 | P2 | health probeが閲覧者の選択キャラを復元しない | 認証ユーザーとセッションを検証 | 対策あり、ローカルに未反映 |
| C1 | 文書 | 神速・逆刻の仕様文で補正率上限と最終damage上限の区別が曖昧 | 文書と実コードの照合 | ローカル文書の整理対象 |

## A1: 装備操作が所有権の移転後も古い情報を信用する

**現在の仕様・実装:** 装備は所有者だけが変更でき、同じ枠の既存装備を解除してから新しい装備を有効にする。一方、`EquipmentService::equip()` は渡されたModelの所有者・出品状態をtransaction開始前に確認し、その後は対象をロック付きで再取得せず保存する。

**再現条件:** 装備POSTが対象Modelを取得した後、別処理で売買が完了し所有者が変わり、その古いModelを使う装備処理が続行される。再現テストでは売買完了時の所有者変更を挿入して、この処理順を決定的に再現した。実際の同時HTTP通信やMariaDBでの競合頻度は未確認。

**実測:** 元所有者の古い装備操作が成功し、購入者の装飾品が **同一枠で2個とも装備中** になった。解除対象は元所有者の装備、更新対象はすでに購入者へ移ったアイテムになるため。

**影響範囲:** 武器・防具・装飾品の変更。能力値計算は装備中の全アイテムを集計するため、枠制約の崩壊が能力値にも伝わる。`unequip()`にも同じ古い所有者情報を使う構造があるが、解除側の競合再現は未実施。

**最小修正案:** transaction内でCharacterとCharacterItemをロック付きで再取得し、所有者・出品状態・装備条件を再検証してから同枠解除と装備を行う。装備市場とのロック順を揃える。DB変更は必須ではない。

**確認方法:** 今回の古いModel再現に加え、MariaDBの独立接続で売買と装備変更を重ね、購入者の装備を元所有者が変更できないこと、各枠1個を確認する。

根拠: `app/Services/EquipmentService.php:26-73`、`app/Http/Controllers/EquipmentController.php:61-92`、`app/Services/EquipmentMarketService.php:90-109`、`app/Models/CharacterItem.php:85-88`、`app/Services/CharacterStatusService.php:71-102`。

## A2: 能力値キャッシュが成長・装備変更をまたいで残る

**現在の仕様・実装:** 最終能力値はCharacter IDをキーにstaticキャッシュされる。基礎能力・職業ランク・装備が変わっても自動無効化されない。通常探索では戦闘後のtelemetry作成が明示的にキャッシュを消すが、地図の連続探索にはその呼出しがない。

**再現条件:** 同一リクエスト内の1戦目で能力値を計算し、報酬によってレベルアップした後、そのまま地図の次戦へ進む。

**実測:** 実際のBattleServiceを呼ぶ2回の地図探索で、1戦目 **Lv1／最大HP10,000**、2戦目 **Lv2／最大HP10,000** となった。別の最小検証では、Lv1→2で正しい最大HPが100→109に上がっても、キャッシュ経由では100のままだった。装備側でも事前計算後の解除では、正しい最大HP100に対してキャッシュと現在HPが200のまま残った。

**影響範囲:** 同一地図バッチ内の成長後の戦闘能力、最大値を使う回復計算、同一処理内で事前計算された装備変更後の能力値。通常のPHPリクエストをまたいで永久に成長しないという指摘ではない。通常探索全体へ一律に広げない。

**最小修正案:** レベル・職業成長や装備更新の後、能力値を再利用する前に対象Characterのキャッシュを無効化する。複数レベルアップのループ中も古い値を再利用しない位置に置く。DB変更なし。

**確認方法:** 地図バッチ1戦目でレベル／職業ランクを上げ、2戦目の最大HP・攻撃などが成長後の値になり、回復上限も一致すること。装備変更前後の同一リクエストでも確認する。

根拠: `app/Services/CharacterStatusService.php:14-21`、`app/Services/LevelService.php:122-130`、`app/Services/MapExplorationBatchService.php:124-137,202,249,465`、`app/Services/BattleService.php:244-265`。比較対象: `app/Services/BattleLogService.php:72-74`。

## A3: 装備の手動解除で現在SPを新しい最大SPへ収めない

**現在の仕様・実装:** 手動の装備変更・解除は現在HPだけを新しい最大HPへ収める。現在SPについて同じ処理がない。無効装備の自動解除ではHP/SPの両方を処理しており、経路によって動作が異なる。

**再現条件:** SPが増える装備で回復し、SPを使い切る前に外す。能力値キャッシュが空の状態でも発生するため、A2とは独立した問題。

**実測:** 最大SPが200→100になった後も **現在SP200** が保存された。BattleServiceも戦闘開始時のSPを最大SPへ収めていない。

**影響範囲:** 手動解除と最大SPが下がる装備交換。上限を超えたリソースが表示・戦闘へ渡る。今回、上限超過SPで何回追加発動できるかの戦闘検証までは実施していない。

**最小修正案:** A2のキャッシュ無効化後に最新最大HP/SPを取得し、両方の現在値を上限へ収める。DB変更なし。

**確認方法:** SP装備を外す／低SP装備へ交換するケースで、保存値・画面・次戦開始値が最大SP以下になること。

根拠: `app/Services/EquipmentService.php:78-84,118-124`、`app/Services/EquipmentAutoUnequipService.php:43-59`、`app/Services/BattleService.php:264-265`。

## B1: 宿屋と他の残高操作の競合・途中失敗

**原因:** ローカルの`InnService::rest()`は渡されたCharacterの値で計算し、transactionとrow lockを持たない。銀行側のロックだけでは、ロックを取らずに古い値を保存する宿屋を防げない。

**再現:** 1,000Gのキャラを宿屋処理が読み込む→銀行へ900G預入→読み込み済みキャラで宿泊。宿代10Gの後、正しい総額990Gに対して、**手持ち990G＋預金900G＝1,890G** になった。また探索状態リセットで例外を注入すると、失敗しても宿代10Gの減算は残った。

**影響:** 処理順によってGold増殖または他処理の加算消失、失敗操作の一部確定。HTTP/MariaDBでの同時実行は未確認であり、古い状態を使う順序をサービスレベルで再現した証拠。

**最小修正案:** `origin/main`にあるtransaction・Character再取得とrow lock・キャッシュ無効化の実装を、現在の宿代ルールを保って取り込む。既存DB変更なし。同じ再現を正しい残高／全rollback期待で通す。

根拠: `app/Services/InnService.php:40-106`、`app/Services/GoldService.php:67-78`、`app/Services/BankService.php:19-29`。取得済みorigin/mainのInnServiceでは対策を確認済み。

## B2: health probeのBlade変数不足

**原因:** `probeMainScreen()`はLivewireコンポーネントを通常のViewとして描画する際、公開プロパティの一部しか渡さない。Viewが参照する`embedded`や`isAccountInfoModalOpen`が欠ける。

**再現:** 実際のホームViewを描画すると **`Undefined variable $embedded`** で失敗した。サービスのcheckは例外を失敗として集約するため、ホームが通常動線で表示できても監視上は異常になり得る。

**最小修正案:** origin/mainの`mount($location)`と公開プロパティ受渡しを取り込む。`GameHealthEndpointTest`はサービスをmockしているため、実描画テストを維持する。DB変更なし。

根拠: `app/Services/GameHealthCheckService.php:106-123`、`resources/views/livewire/main-screen.blade.php:69,1562`、`tests/Feature/GameHealthEndpointTest.php:10-34`。origin/mainの修正例: `9eef1caf`。

## B3: release readinessの対象不足

**原因:** `extra_content.contents`には`equipment_book`と`hero_trials`があるが、`contentIssues()`の分岐にはない。

**実測:** `valzeria:validate-release-readiness --all`が終了コード1で両方を「未対応の追加コンテンツ」と報告する。コンテンツをOFFにしても`--all`では止まる。公開スクリプトがこのチェックを必須実行するため、現在のローカル一式は公開ゲートを通らない。

**最小修正案:** origin/mainに存在する両検証を取り込む。単にチェックを無効化・成功扱いにしない。全設定キーに検証処理があることもテストする。DB変更なし。

根拠: `app/Services/ReleaseReadinessService.php:30-38`、`config/extra_content.php:29,45`、`scripts/deploy/remote-release.sh:122`。

## B4: health probeが選択キャラクターのセッションを変える

**原因:** `withProbeUser()`は認証ユーザーだけ復元し、`User::currentCharacter()`が変更する`current_character_id`を復元しない。

**実測:** 閲覧者の選択キャラID2で呼ぶと、認証ユーザーは復元された一方、選択キャラは監視用ID1になった。`currentCharacter()`には所有者で絞る処理があるため、これを「他人のキャラとして操作できる」とは扱わない。

**影響:** 複数キャラを持つ閲覧者などで次の操作時のフォールバック・選択変更を起こす可能性。探索対象セッションやrequest属性も復元処理が欠けているが、今回の動的検証は選択キャラだけ。

**最小修正案:** origin/mainのセッション・request属性の保存復元を取り込み、成功／例外の双方で元の選択が変わらないことを確認する。DB変更なし。

根拠: `app/Services/GameHealthCheckService.php:146-162`、`app/Models/User.php:36-52`。origin/mainの修正例: `9eef1caf`。

## C1: 仕様書内の上限表記が紛らわしい

ローカル`docs/DOMAIN_RULES.md:48`は神速の間を「最大+60%まで上限なし」、49行は逆刻の間を「上限なし」と「最大+40%」の両方で説明している。

現コードでは神速の補正率は`min(0.60, ...)`、逆刻は5段階／`min(0.40, ...)`で上限がある。「最終damageそのものには上限がなく、補正率には上限がある」という意味なら両立するが、現在の文章では区別が不明瞭。**文書の整理対象であり、戦闘計算のバグと断定しない。** 既存の明示裁定と最新版文書を照合し、両方の上限を分けて記述する。もし補正率自体の無制限化を意図していたのであれば要裁定とし、コードの上限を独断で削除・変更しない。

根拠: `app/Services/Battle/RoomRules/DivineSpeedPvPRoomRule.php:15,85-88`、`app/Services/Battle/RoomRules/ReverseTimePvPRoomRule.php:15-17,92-96`。

## 既存テスト3件の失敗の切り分け

全体実行後、失敗した3メソッドだけを別プロセスで再実行した。探索力と風の護符は再度失敗し、PvP telemetryは成功した。3件をそのままゲーム不具合3件として加算しない。

| テスト | 全体実行での差異 | 分類と次の確認 |
|---|---|---|
| `ExplorationStaminaServiceTest::test_summary_normalizes_legacy_50_based_stamina_to_current_base_max` | 期待250／実際750 | 現在日時を固定していない。2026-07-01から今回実行日までの自然回復はシルバーウィークをまたぎ、その間の上限750へ到達して終了後も保持される。`DOMAIN_RULES.md:5`の持越し規則と整合するため、通常期の旧形式移行テストとして日時を固定するのが最小案 |
| `WindCharmAccessorySpecializationMigrationTest::test_wind_charm_family_moves_its_existing_total_to_agility_only` | SS+30の期待敏捷544／実際1600 | テストの期待値が現在の強化設定と不一致。現設定は旧目標200を能力8倍スケールへ戻して1600。`config/equipment_enhancement.php:30-33`、`EquipmentEnhancementService.php:285-332,612-631`に一致する。544へゲーム数値を戻さず、既存裁定・強化テストと期待値を同期する |
| `JobArtV2BattleTelemetryTest::test_real_arena_pvp_battle_writes_one_row_with_actual_action_counts` | 8ターンに対して行動数16 | 3メソッドのみの再実行では成功し、実行順依存の兆候。原因は未確定。テストが「各actorは1ターン最大1行動」と仮定しており、敏捷差の追加行動仕様と不整合。またstatic能力値キャッシュがテストDBの再作成・ID再利用をまたいで残る可能性があるため、fixtureとキャッシュの分離を確認する |

再実行ログ: `storage/logs/cross-audit-20260925-failures-isolated.xml`（3件、1成功／2失敗、68 assertions）。

## 検証結果と限界

| 検証 | 結果 |
|---|---|
| PHP構文 | 2,157ファイル成功（監査用scratchは対象外） |
| 既存PHPUnit全体 | 2,793件、2,790成功／3失敗、56,021 assertions、約669秒。上記で切り分け |
| 追加再現テスト | 10ケース中9 assertion failure＋1描画error。意図する不変条件が破れることを確認するテストであり、10種類の独立バグという意味ではない |
| フロントエンドNodeテスト | 9件成功 |
| 主要マスタ検証 | 奥義説明・効果種別、ダンジョン参照とも成功 |
| 全追加コンテンツの公開前検証 | 失敗。B3の2件 |
| ルート一覧生成 | 336件。全画面が実際に描画・操作できる証明ではない |
| Viteビルド | 成功、約362秒。`storage/audits/cross-audit-20260925-build`へ別出力。Tailwind処理が大部分を占める。fontaine未導入の任意機能警告あり |

既存のPHPUnitは`APP_ENV=testing`、SQLiteの`:memory:`へ固定して実行した。主要マスタ・公開前チェックはローカルSQLiteに対する読取り検証。マイグレーション／fixture書込みは破棄されるテスト用DB内のみ。

本番・MariaDB実競合・全戦闘経路の実アカウント操作・スマホ実機の表示は未確認。テストの成功は全機能の無欠陥や全画面の正常表示を意味しない。現在の作業ツリーには非常に多くの既存変更があり、全差分を本番へ運ぶ判断には使わない。

`composer.json`の`test`／`verify`にはprocess timeout解除がなく、今回のPHPUnit所要時間はComposer既定300秒を超える。今回はPHPUnitを直接実行して完走させた。`npm run verify`そのものは再実行していないため、この環境でのタイムアウト発生を今回の実測結果とはしない。

## 再現資産

- 監査用テスト: `scratch/cross-audit-20260925/CrossAuditReproductionTest.php`（標準tests外、修正前の不変条件違反を検出）。
- 初回8ケース: `storage/logs/cross-audit-20260925-reproduction.xml`。
- 追加2ケース: `storage/logs/cross-audit-20260925-additional.xml`。
- 全体: `storage/logs/cross-audit-20260925-phpunit.xml`。
- ルート一覧: `storage/logs/cross-audit-20260925-routes.json`。

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
& C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64\php.exe -d memory_limit=768M vendor\bin\phpunit scratch\cross-audit-20260925\CrossAuditReproductionTest.php
```

## 修正の進め方

1. 最新mainを基準に、A1の所有権・同枠装備の整合性を修正する。
2. A2のキャッシュ無効化とA3のSP上限処理を、成長・装備・地図連続探索で確認する。
3. B1〜B4は新規の別実装を増やさず、既修正のmainとの差分として作業ツリーの整理対象にする。
4. C1は既存裁定を確認して文書だけを整理し、ゲーム数値の変更と混ぜない。

各修正は別途の実装作業で行い、公開には対象差分を限定した検証と明示の公開依頼を必要とする。
