# 実装指示書: Gold経済ダッシュボード（依頼書D）

Status: 未着手（人間確認後にCodexへ渡す）

## 目的
管理者が `gold_transactions.type` をもとに、Goldの発行・回収・netを日別/type別に確認できる読取専用ダッシュボードを追加する。NPC調達報酬をGold発行源として継続監視できる状態にする。

## 背景
- TASK_B_GOLD_ECONOMY_INVESTIGATION で、Gold経済の判断には `gold_transactions` の日別/type別集計が必要と整理した。
- NPC調達報酬（type=`npc_procurement_delivery`）は、素材のNPC売却価格より高くなること自体は暫定許容するが、Gold発行源として監視対象とする。
- 既存の管理者向け分析画面として `/admin/operator-analytics` と `OperatorAnalyticsManager` があり、最小変更ではここへGold経済セクションを追加する。
- `bank_deposit` / `bank_withdraw` はGoldの発行・回収ではなく、手持ちGoldと銀行預金の移転として別枠表示する。

## 現状の問題
- `gold_transactions` にはtype付きのGold増減履歴があるが、管理者が日別/type別の発行・回収・netを一覧できる画面がない。
- NPC調達報酬が実際にどの程度Goldを発行しているか、管理画面だけでは確認しづらい。
- Gold経済の異常増加や疑似的な錬金経路を、運営が早期に検知しにくい。

## 実装対象
- 管理者向け読取専用ダッシュボードとして、Gold経済セクションを追加する。
- 第一候補は既存 `/admin/operator-analytics` の `OperatorAnalyticsManager` と対応Bladeへのセクション追加とする。
- `gold_transactions` を日別/type別に集計し、発行・回収・netを表示する。
- `bank_deposit` / `bank_withdraw` は発行・回収ではなく移転として別枠表示する。
- `market_purchase` / `market_sale` は発行・回収に単純分類せず、まず「市場取引」または「要分類」として別枠表示する。
- NPC調達報酬を監視できる集計を表示する。
- 期間指定のデフォルトは直近30日、最大表示期間は90日までとする。
- 既存の管理者分析画面の期間指定UIがある場合も、直近30日デフォルト・最大90日の上限を守る。

## 実装対象外（重要）
- Gold報酬・費用・売値の数値変更は行わない。
- Seeder変更は行わない。
- `config/gold.php` の配列値変更は行わない。
- `items.sell_price` の仕様変更は行わない。
- NPC調達報酬の単価・数量・生成ロジックは変更しない。
- 市場取引の経済分類を推測で確定しない。
- `market_sale` / `market_purchase` を単純な発行・回収として固定しない。
- 期間指定の上限値やデフォルト期間をCodexが勝手に決めない。
- DB書き込みは行わない。
- migration作成は行わない。
- index追加、日次集計テーブル、キャッシュテーブル作成は行わない。必要性が見えた場合は別タスクとして報告する。
- 課金処理、輝石、有償/無償残高、Stripe、`kiseki_transactions` には触れない。
- `kiseki_transactions`、Stripe、輝石残高、有償/無償残高は参照も変更もしない。
- プレイヤー向け画面には表示しない。
- 本番DB照会は行わない。
- デプロイしない。
- コミットしない。
- 依頼範囲外のリファクタリング・文言変更は行わない。

## 変更範囲
- 想定ファイル:
  - `app/Livewire/Admin/OperatorAnalyticsManager.php`
  - `resources/views/livewire/admin/operator-analytics-manager.blade.php`
  - 必要に応じて `docs/CODEMAP.md`
  - 必要に応じて `docs/FEATURE_STATUS.md`
- 想定しない範囲:
  - `app/Services/GoldService.php`
  - `app/Services/NpcProcurementRequestService.php`
- `app/Services/BankService.php`
  - `config/gold.php`
  - `database/migrations/*`
  - `database/seeders/*`
  - `kiseki_transactions` を参照する課金・輝石関連処理
  - 課金・輝石関連ファイル

## 既存仕様への影響
- Gold: 市場、素材交換所、鍛冶費用、宿代、銀行、倉庫Gold拡張、総資産番付、換金品売値、救済宿泊の発生率に関連する。今回は読取専用の管理者表示追加であり、Goldの増減処理自体は変更しない。
- NPC調達と市場: NPC調達報酬と市場売買のGold流量を監視対象に含める。NPC調達・市場の挙動は変更しない。
- 管理者機能: `/admin/operator-analytics` に管理者向け情報を追加する。プレイヤー可視範囲には影響させない。
- 課金・輝石: 対象外。Goldから輝石への変換経路を作らない。

## DB変更
- なし。
- migration作成禁止。
- index追加も本タスクでは行わない。パフォーマンス懸念が出た場合は、実装後レポートで別タスク候補として報告する。

## 集計対象type一覧
実装前に `rg` と実コードで最新のtype一覧を確認し、漏れがあれば分類に追加すること。TASK_B時点の確認対象は以下。

### 発行
- `battle_reward`
- `material_sale`
- `equipment_sale`
- `npc_procurement_delivery`
- `bank_withdraw` は移転のため発行には含めない

### 回収
- `inn`
- `shop_equipment_purchase`
- `equipment_enhancement`
- `equipment_evolution`
- `material_exchange`
- `adventure_support_purchase`
- `exploration_defeat_gold_loss`
- `bank_deposit` は移転のため回収には含めない

### 移転
- `bank_deposit`
- `bank_withdraw`

### 市場取引
- `market_purchase`
- `market_sale`
- プレイヤー間市場は基本的にGoldの移転であり、発行ではない。購入側の支払い全額を回収扱いにするとGold消滅量を過大表示する可能性があるため、発行・回収には単純分類しない。
- NPC出品購入だけを回収扱いにしたい場合は、metadataや関連実装で確実に判定できるかを確認する。確実に判定できない場合は回収に混ぜず、「市場取引」または「要分類」として報告する。
- 市場手数料が `gold_transactions` 上でtype分離されている場合は回収扱いにしてよい。ただし、`market_purchase` / `market_sale` に内包されている場合は、推測で手数料分を切り出さない。不明な場合は「要分類」として報告する。

### 要分類確認
- 実装時点で上記以外の `gold_transactions.type` が見つかった場合は、推測で分類せず「要分類」として別枠表示し、最終報告で人間判断を求める。

## 発行/回収/移転の分類ルール
- 発行: システムからプレイヤーへGoldが増える取引。
- 回収: プレイヤーからシステムへGoldが消える取引。
- 移転: 手持ちGoldと銀行預金など、同一プレイヤー内またはプレイヤー間でGoldの所在が変わるだけの取引。
- 市場取引: `market_sale` / `market_purchase` は、プレイヤー間売買とNPC出品で意味が変わる可能性があるため、発行・回収に単純分類しない。metadataや既存実装で確実に判定できる範囲だけ注記し、判定できない分は「市場取引」または「要分類」とする。
- net計算: 発行 - 回収で算出し、`bank_deposit` / `bank_withdraw` は原則含めない。市場取引も、回収・発行として確実に分類できない限りnet計算に含めない。

## 表示項目
- 期間指定:
  - デフォルトは直近30日。
  - 最大表示期間は90日まで。
  - 既存の管理者分析画面の期間指定に合わせる場合も、このデフォルトと上限を守る。
- サマリー:
  - 期間内の発行Gold合計
  - 期間内の回収Gold合計
  - net（発行 - 回収）
  - 移転Gold合計
  - 市場取引Gold合計
  - 要分類typeの件数・合計
- 日別集計:
  - 日付
  - 発行Gold
  - 回収Gold
  - net
  - 移転Gold
  - 市場取引Gold
- type別集計:
  - type
  - 分類（発行/回収/移転/市場取引/要分類）
  - 件数
  - 正のamount合計
  - 負のamount絶対値合計
  - net
- NPC調達監視:
  - `npc_procurement_delivery` の件数
  - `npc_procurement_delivery` の合計Gold
  - 1件あたり平均Gold
  - 日別推移
  - 期間内の上位キャラ（character_id、キャラ名、件数、合計Gold）
- 注記:
  - bank系は発行・回収ではなく移転として扱う。
  - 市場取引は発行・回収へ単純分類せず、判定できない分は市場取引または要分類として扱う。
  - 本画面は読取専用で、Gold残高や取引履歴を変更しない。

## パフォーマンス注意点
- `gold_transactions` は日別/type別集計で読むため、対象期間を必ず絞る。
- デフォルト期間は直近30日、最大表示期間は90日までにする。
- 既存indexを実装前に確認する。
- TASK_B時点では `type` と `character_id, created_at` のindexが確認済みだが、実装時に最新のmigration/schemaを再確認する。
- `DATE(created_at)` など、indexを効かせにくい集計式に注意する。期間の絞り込みは必ず `whereBetween('created_at', ...)` を先に適用してから、日別/type別集計する。
- 本タスクではmigration禁止のため、index追加は行わない。90日を超える集計、index追加、日次キャッシュ、集計テーブル作成が必要になりそうな場合は、別タスクとして報告する。
- 全期間・全件集計をデフォルトにしない。

## 本番DBへの影響
- 実装時に本番DB照会は行わない。
- ダッシュボードは読取専用SELECTのみとする。
- DB書き込み、残高更新、取引作成、集計テーブル作成は行わない。
- 本番リリース後に管理者が画面を開いた場合、最大90日までの指定期間の `gold_transactions` を読む。重い場合は期間短縮や別タスクでのindex/キャッシュを検討する。

## 画面/UI変更
- 対象画面: 管理者向け `/admin/operator-analytics`
- 対象Blade: `resources/views/livewire/admin/operator-analytics-manager.blade.php`
- プレイヤー向け画面には表示しない。
- 管理画面の既存デザインに合わせ、表形式で過不足なく表示する。
- スマホ幅対応は管理画面の既存方針に合わせる。横に長い表は既存と同様の横スクロール/折り返し方針を使う。

## バックエンド変更
- 集計ロジックは `OperatorAnalyticsManager` 側へ寄せる。
- Bladeには複雑な集計ロジックを書かない。
- Gold残高や取引履歴を変更するServiceは呼ばない。
- `GoldService::add()` / `GoldService::spend()` / `GoldService::record()` は呼ばない。

## 管理者機能への影響
- あり。管理者向け分析画面にGold経済の読取専用セクションを追加する。
- 誤操作リスクを避けるため、操作ボタンや更新ボタンは追加しない。既存の期間フィルタのみ利用する。

## ログ・更新履歴への影響
- 公開ログ: なし。
- `gold_transactions`: 読み取りのみ。新規記録しない。
- `config/admin_update_summaries.php`: 追記必須にしない。管理者向け読取専用分析の追加でプレイヤー可視挙動は変わらないため。実装時に必要と判断した場合も勝手に追記せず、完了報告で「追記案」として提示する。
- docs同期:
  - `docs/CODEMAP.md`: 管理者分析画面の重要な表示追加として必要なら更新する。
  - `docs/FEATURE_STATUS.md`: 管理者向け分析機能の状態として必要なら更新する。
  - `docs/DOMAIN_RULES.md`: 仕様・バランス値は変更しないため原則不要。

## エラーハンドリング
- 対象期間にデータがない場合は、空表ではなく「該当するGold取引はありません」と表示する。
- 未分類typeがある場合は、エラーにせず「要分類」として表示し、実装報告で人間判断を求める。
- 市場取引を発行・回収に分類できない場合は、エラーにせず「市場取引」または「要分類」として表示する。
- 集計クエリ例外時にGold取引を変更する処理へフォールバックしない。

## セキュリティ注意点
- 管理者画面の認可を既存 `/admin/operator-analytics` と同じ仕組みに乗せる。
- プレイヤー向け画面・APIへGold集計を露出しない。
- 個人情報、メールアドレス、課金情報は表示しない。
- character_idとキャラ名は、既存管理画面で許容される範囲に限定する。
- 課金・輝石関連テーブルには触れない。
- `kiseki_transactions`、Stripe、輝石残高、有償/無償残高は参照も変更もしない。

## テスト観点
- 管理者のみが画面を見られること。
- 対象期間を変えるとGold集計が変わること。
- 発行typeが正の発行合計に入ること。
- 回収typeが回収合計に絶対値で入ること。
- `bank_deposit` / `bank_withdraw` が発行・回収に混ざらず、移転として別枠表示されること。
- `bank_deposit` / `bank_withdraw` がnet計算に原則含まれないこと。
- `market_purchase` / `market_sale` が発行・回収に単純分類されず、市場取引または要分類として別枠表示されること。
- 市場手数料がtype分離されていない場合に、推測で手数料分を切り出していないこと。
- `npc_procurement_delivery` の件数・合計・平均・上位キャラが表示されること。
- 未分類typeがある場合に要分類として表示されること。
- 対象期間にデータがない場合に画面が壊れないこと。
- Gold残高・`gold_transactions` 件数が画面表示前後で変わらないこと。

## 手動確認手順
1. ローカル環境で管理者として `/admin/operator-analytics` を開く。
2. Gold経済セクションが表示されることを確認する。
3. 初期表示が直近30日であることを確認する。
4. 期間指定を変更し、日別/type別集計が更新されることを確認する。
5. 90日を超える期間指定ができない、または90日に丸められることを確認する。
6. `bank_deposit` / `bank_withdraw` が移転枠に表示され、発行・回収合計とnet計算に混ざらないことを確認する。
7. `market_purchase` / `market_sale` が市場取引または要分類として別枠表示され、発行・回収合計へ単純混入しないことを確認する。
8. `npc_procurement_delivery` の監視項目が表示されることを確認する。ローカルに実績がない場合は0件表示で画面が崩れないことを確認する。
9. 表示前後で `select count(*) from gold_transactions` と代表キャラのGold残高が変わっていないことを確認する。
10. `php -l` と、可能なら管理画面関連の狭いテストまたは画面表示確認を行う。

## 完了条件
- [ ] 管理者向け分析画面に、Gold経済の読取専用セクションが追加されている。
- [ ] 日別/type別の発行・回収・netが表示されている。
- [ ] `bank_deposit` / `bank_withdraw` が移転として別枠表示されている。
- [ ] `bank_deposit` / `bank_withdraw` がnet計算に原則含まれていない。
- [ ] `market_purchase` / `market_sale` が発行・回収に単純分類されず、市場取引または要分類として別枠表示されている。
- [ ] 初期表示が直近30日で、最大表示期間が90日までに制限されている。
- [ ] NPC調達報酬の件数・合計・平均・日別推移・上位キャラを確認できる。
- [ ] 未分類typeがあれば要分類として表示され、報告されている。
- [ ] DB書き込み、migration、Seeder変更、数値変更、課金処理変更がない。
- [ ] 本番DB照会、デプロイ、コミットを行っていない。
- [ ] パフォーマンス上の懸念と、index/キャッシュが必要かどうかを報告している。

## 実装しない場合の代替SQL案
本番DB照会はこのタスクでは行わない。人間が手元で実行するための読取専用SQL案として提示する場合は、接続先を確認したうえでSELECTのみを使う。

### 日別/type別

```sql
select
  date(created_at) as day,
  type,
  count(*) as tx_count,
  sum(case when amount > 0 then amount else 0 end) as positive_amount,
  sum(case when amount < 0 then -amount else 0 end) as negative_amount,
  sum(amount) as net_amount
from gold_transactions
where created_at >= :from
  and created_at < :to
group by date(created_at), type
order by day desc, type asc;
```

注: `:from` / `:to` は最大90日幅に制限してから渡す。`market_purchase` / `market_sale` は、この集計結果から発行・回収へ単純分類しない。

### 発行/回収/移転サマリー

```sql
select
  case
    when type in ('bank_deposit', 'bank_withdraw') then 'transfer'
    when type in ('market_purchase', 'market_sale') then 'market'
    when type in ('battle_reward', 'material_sale', 'equipment_sale', 'npc_procurement_delivery') then 'issue'
    when type in ('inn', 'shop_equipment_purchase', 'equipment_enhancement', 'equipment_evolution', 'material_exchange', 'adventure_support_purchase', 'exploration_defeat_gold_loss') then 'sink'
    else 'zero'
  end as bucket,
  count(*) as tx_count,
  sum(abs(amount)) as gross_amount,
  sum(amount) as net_amount
from gold_transactions
where created_at >= :from
  and created_at < :to
group by bucket
order by bucket asc;
```

注: `market_purchase` / `market_sale` に市場手数料が内包されている場合、このSQLでは手数料分を切り出さない。手数料がtype分離されている場合のみ、別途そのtypeを回収として分類する。

### NPC調達報酬 上位キャラ

```sql
select
  character_id,
  count(*) as delivery_count,
  sum(amount) as total_gold,
  avg(amount) as avg_gold
from gold_transactions
where type = 'npc_procurement_delivery'
  and created_at >= :from
  and created_at < :to
group by character_id
order by total_gold desc
limit 20;
```

## 更新情報サマリ案
- category: internal
- title（15〜35字）: Gold経済の管理分析を追加
- detail（管理者向け日本語）: Goldの発行・回収・移転を日別/type別に確認できる管理者向け分析を追加しました。

注: `config/admin_update_summaries.php` への追記は必須ではない。実装時に必要と判断した場合も勝手に追記せず、完了報告で追記案として提示する。
