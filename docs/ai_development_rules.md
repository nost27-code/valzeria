# ヴァルゼリアの冒険者 AI開発ルール

この文書は、CodexやAIエージェントが「ヴァルゼリアの冒険者」を実装・調査・修正するときに必ず守る作業ルールです。

> **2026-07-02更新**: 仕様の優先順位は AGENTS.md の「Source of truth order」に一本化しました（コード > DOMAIN_RULES.md > AI_CONTEXT.md > valzeria_spec.md > その他docs）。本文書の作業ルールは有効ですが、本文書と AGENTS.md / docs/dev-os/ が食い違う場合は後者を優先してください。新しいルールの追記先は AGENTS.md または docs/dev-os/ とし、本文書には追記しないでください。

## 実装時に必ず参照するファイル

作業前に、変更内容に応じて以下を確認します。

- 正仕様: `docs/valzeria_spec.md`
- AI開発ルール: `docs/ai_development_rules.md`
- プロジェクト教訓: `docs/project_knowledge.md`
- ルート: `routes/web.php`
- 街/メイン画面: `app/Livewire/MainScreen.php`
- 戦闘入口: `app/Http/Controllers/BattleController.php`
- 探索処理: `app/Services/ExplorationService.php`
- 戦闘処理: `app/Services/BattleService.php`, `app/Services/Battle/*`
- レベルアップ: `app/Services/LevelService.php`
- BP割り振り: `app/Services/BonusPointService.php`
- 職業/転職: `app/Services/JobService.php`, `app/Services/CharacterJobChangeService.php`, `app/Livewire/JobChange.php`
- ステータス計算: `app/Services/CharacterStatusService.php`
- 装備/ドロップ: `app/Services/DropService.php`, `app/Services/EquipmentService.php`, `app/Http/Controllers/EquipmentController.php`
- ショップ/支給: `app/Services/ShopService.php`, `app/Http/Controllers/ShopController.php`
- 宿屋: `app/Services/InnService.php`, `app/Http/Controllers/InnController.php`
- 都市/ダンジョン進行: `app/Services/AreaService.php`, `database/seeders/CitySeeder.php`, `database/seeders/AllDungeonsSeeder.php`
- 輝石: `config/kiseki.php`, `app/Http/Controllers/KisekiShopController.php`, `app/Services/KisekiDropService.php`, `app/Services/AdventureSupportService.php`
- ヴァルモン: `app/Services/ValmonService.php`, `app/Http/Controllers/ValmonController.php`
- チャンプ戦: `app/Services/ChampBattleService.php`, `app/Http/Controllers/ChampBattleController.php`
- 管理画面: `app/Livewire/Admin/*`, `resources/views/livewire/admin/*`
- デプロイ: `local_deploy.php`, `server_deploy_api.php`

## Gold経済のルール

- Goldは通常ゲーム内通貨として扱う。輝石は課金/支援通貨として役割を分ける。
- 主なGold入手は素材・装備・換金品の売却にする。敵からの直接Goldは低確率かつ少額にする。
- `characters.money`、`enemies.gold_reward`、`battle_logs.gold_gained`、`items.sell_price`、`materials.npc_sale_price` は正仕様として扱う。
- 売却価格が0または未設定の素材・装備は売却不可にする。
- 重要素材、討伐証、刻印、進化キー、課金/支援系アイテムは安易に売却可能にしない。
- 合成費用や市場購入にGoldを使う場合は、基本ゲームループを重くしすぎない。
- 輝石からGoldへの交換は慎重に扱う。Goldから輝石への変換は導入しない。

## 既存仕様を勝手に変更しないルール

- 基本ループ「街 -> ダンジョン -> 戦闘 -> 報酬 -> 回復/装備/進行解放」を壊さない。
- 通常探索とボス挑戦を混ぜない。ボスを通常探索にランダム出現させない。
- プレイヤーレベルと職業ランクを混同しない。
- `job_level` は内部名として残っていても、表示では職業ランクと呼ぶ。
- ステータス表記は HP / MP / ATK / DEF / MAG / SPR / SPD / LUK に寄せる。
- Bladeに戦闘計算、報酬計算、解放判定などの複雑なロジックを書かない。
- Controllerに巨大な処理を書かず、既存Service層へ寄せる。
- 一度に多数の機能をまとめて実装しない。依頼範囲に沿って最小差分にする。
- 古いdocs、文字化けした設定、実装途中の残骸を正仕様として採用しない。

## 破壊的変更がある場合は事前に明示するルール

以下に該当する作業は、実装前に影響範囲、リスク、戻し方を明示します。

- migrationで既存カラムやテーブルを削除、リネーム、型変更する。
- Seederで `truncate`、全件上書き、大量更新を行う。
- マスタID、都市ID、ダンジョンID、敵ID、アイテムID、素材IDを変更する。
- 既存キャラクターの進行状態、所持品、素材、輝石、職業履歴に影響する。
- 課金、輝石、有償/無償残高、Stripe webhookに触る。
- デプロイ、サーバー上のファイル削除、DBマイグレーション実行を伴う。

## マスタデータ変更時の確認項目

- 対象マスタの正本を確認する。TSV、Seeder、DB直書き、Markdownが混在する場合は正本を特定してから変更する。
- ID重複がないか確認する。
- 敵、ダンジョン、都市、アイテム、素材、ドロップの参照先が存在するか確認する。
- 同名敵を名前だけで紐付けない。必要なら `area_id` / `dungeon_id` / `city_id` など複合条件で特定する。
- 職業解放条件の `required_job_id` や job key が存在するか確認する。
- ボス撃破後の解放先ダンジョン、次都市が存在するか確認する。
- TSV/CSVは列ズレ、空タブ、ヘッダ変更に注意する。
- Seeder変更後は代表レコードの値を確認する。件数、ID、ステータス倍率、drop_rate、required_levelなど。
- Gold系カラムは正仕様として利用する。ただし高額化しすぎず、売却価格0は売却不可として扱う。

## 戦闘ロジック変更時の確認項目

- 通常探索が `is_boss=false` の敵のみを抽選すること。
- ボス挑戦が `is_boss=true` の敵を対象にすること。
- 勝利時にEXP、職業EXP、ドロップ、低確率Gold、輝石判定、称号判定が壊れていないこと。
- Goldが毎戦固定報酬になっていないこと。
- 敗北時にHP/MP、探索状態、戦利品ロスト、ヴァルモン卵ロスト/保護が意図通りであること。
- レベルアップ時にLv255上限、BP+1、HP/MPクランプが守られること。
- 職業ランクアップ、★10マスター、上位職解放条件に副作用がないこと。
- BattleResult、battle_logs、結果画面、公開ログの表示が矛盾しないこと。
- PRGパターンを守り、POST戦闘処理後に同じ戦闘が再実行されないこと。

## 課金・輝石まわりの注意点

- 輝石はゴールドの代替汎用通貨にしない。
- 有償輝石、無償輝石、合計輝石の整合性を保つ。
- Stripe Checkout、Webhook、`stripe_orders`、`kiseki_transactions` の二重処理を防ぐ。
- 支援アイテム購入時はロック、残高不足、消費内訳、ログ作成を確認する。
- 決済ID、price_id、product_id、メール、秘密鍵などの秘匿情報をdocsやログに書かない。
- 輝石ドロップは無償輝石として扱い、日次上限とログを守る。
- 課金要素は通常成長、装備更新、職業育成の楽しさを壊さない範囲に限定する。

## 管理者機能・不正対策まわりの注意点

- 管理画面は本番データを直接変えうるため、保存処理、削除処理、大量更新の影響を明示する。
- マスタ管理ではGold価格を正仕様として扱うが、売却不可は0で表す。
- 行動ログでは、戦闘、Gold、ドロップ、輝石、課金支援、装備進化、分解、ヴァルモンを確認できるようにする。
- 不正チェックでは以下を見る。
  - 短時間の大量戦闘
  - 異常なEXP、職業EXP、BP増加
  - 輝石の異常増減
  - 支援アイテムの二重購入または二重付与
  - 素材、装備、ヴァルモン卵の異常増殖
  - 複数アカウント疑い
- 管理者機能を追加するときは、閲覧権限、操作ログ、誤操作防止を考慮する。

## 実装後に確認すべきテスト・チェック項目

最低限、変更範囲に応じて以下を確認します。

- 画面が表示できる。
- スマホ幅で主要UIが崩れない。
- 通常探索で戦闘できる。
- ボス挑戦でボスと戦える。
- 通常探索にボスが出ない。
- 勝利時にEXP、職業EXP、ドロップが入る。
- 勝利時Goldは低確率で、毎回固定ではない。
- 敗北時のHP/MP、探索終了、戦利品ロストが正しい。
- レベルアップ時にLv255上限とBP+1が守られる。
- BP割り振りが任意ステータスへ反映される。
- 職業ランクアップ、★10マスターが正しく表示される。
- 転職条件、転職後Lv1、能力継承が意図通りである。
- 装備変更がステータスに反映される。
- 売却価格がある装備は売却でき、重要装備や価格0の装備は売却できない。
- ボス撃破で次ダンジョンまたは次都市が解放される。
- 輝石購入、輝石消費、輝石ドロップのログが整合する。
- ヴァルモン卵、相棒、餌、素材発見が変更範囲内で壊れていない。
- チャンプ戦の挑戦、クールダウン、チャンプ交代、報酬が壊れていない。
- 管理画面の保存、検索、一覧、ログ表示が壊れていない。

可能なら以下を実行します。

- `php -l` で変更したPHPファイルの構文確認。
- Laravelのテストが実行可能な環境なら `php artisan test`。
- DBやSeederに触れた場合は代表データの件数と参照整合性を確認。
- フロント表示に触れた場合はローカルサーバーとブラウザ確認。

## Codexが作業完了時に報告すべき形式

作業完了時は以下を簡潔に報告します。

- 変更対象ファイル
- 変更理由
- 実装内容
- DB変更の有無
- 動作確認手順と結果
- 注意点、未確認事項、仕様差分

報告例:

```text
変更対象:
- docs/valzeria_spec.md
- docs/ai_development_rules.md

実装内容:
- Gold再実装、輝石、Lv255、BP、転職、都市進行、戦闘、ヴァルモン、チャンプ戦、管理者機能の正仕様を整理。
- 実装との差分を要確認事項として記録。

DB変更:
- なし

確認:
- Markdownファイル作成を確認。
- 既存コードの該当箇所を参照。

注意点:
- money/gold系カラムと古い案内文が残るため、別タスクで撤去または表示修正が必要。
```
