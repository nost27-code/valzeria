# 調査指示書: Gold経済 読取専用調査タスク（依頼書B）

Status: 未着手（人間確認後にCodexへ渡す）

## 目的
docs/dev-os/GOLD_ECONOMY_REVIEW_2026-07-02.md の「仮置き」を実データで置き換え、Gold経済を推測でなく実測で判断できる状態にする。**本タスクは読取・調査・レポート・設計案までで、いかなる数値変更・実装も行わない。**

## 背景
- Gold増減はすべて `gold_transactions.type` 付きで記録済み（GoldService::record）。
- 供給側で唯一の「労働型ミント」であるNPC調達納品報酬（type=`npc_procurement_delivery`）が最大のインフレ監視対象。
- 装備売値はコード上 `items.sell_price` を参照する一方、config/gold.php にランク別売値表 `equipment_sell_prices` が並存しており、正本が不明。

## 調査対象1: 装備売値の正本調査
確認すること:
- 売却時に実際に参照されるのは `items.sell_price` か（GoldService::equipmentSalePrice を起点にコールグラフを追う）
- `config('gold.equipment_sell_prices')` の全参照箇所（`rg "equipment_sell_prices"` をapp/database/routes/resources全域で）。Seeder・migration・管理画面での使用有無
- 結論を「現役の正本 / Seederの初期値供給用 / 未使用残骸」のいずれかに分類し、根拠を添える
- 未使用または初期値供給用の場合: config/gold.php に「正本はitems.sell_price。この表は◯◯用」のコメント追記**案**と、docs/DOMAIN_RULES.md への1行追記**案**を作成（**適用はしない**）

## 調査対象2: NPC調達報酬の分布確認
確認すること（DB照会は読取SELECTのみ。ローカルDBを優先し、本番参照が必要な場合は事前に人間へ確認）:
- `npc_procurement_request_materials.reward_gold_per_unit` の分布（最小/最大/平均/素材帯別）
- `npc_procurement_requests.reward_gold_on_complete` の分布と、依頼1件あたりの要求数量レンジ
- 依頼生成ロジック（NpcProcurementRequestGenerationService）上の単価・数量の決定式と上限の有無
- 1依頼あたりの最大/平均Gold発行量、および1キャラ・1日あたりの理論上限（依頼の同時受注数・更新頻度から算出）
- 対象素材のドロップ率・入手手段と突き合わせ、「探索→納品」の時給Gold試算（序盤/中盤/終盤の3帯）
- 素材交換所・市場で安価に量産できる素材が高単価の納品対象になっていないか（無限錬金経路の有無）
- 実際の発行実績: `gold_transactions` の type=`npc_procurement_delivery` の日別合計・上位キャラ（可能なら）

## 調査対象3: Gold経済ダッシュボード設計案（実装しない）
設計案に含めること:
- `gold_transactions` を type別・日別に集計し、発行（amount>0）/消滅（amount<0）の2系列と純増減を表示する管理画面の設計
- 最小変更範囲案: 既存 `/admin/operator-analytics`（OperatorAnalyticsManager）への1セクション追加を第一候補とし、追加するクエリ・Blade・ルートの見積もり（新規画面が必要ならその理由）
- typeの一覧と表示名マッピング案（battle_reward, material_sale, equipment_sale, npc_procurement_delivery, inn, equipment_enhancement, equipment_evolution, material_exchange, shop_equipment_purchase, exploration_defeat_gold_loss, bank_deposit/bank_withdraw ほかgrepで網羅。bank系は移転であり発行/消滅に含めない扱いを明記）
- 集計パフォーマンス懸念（index有無の確認、日次キャッシュ要否）
- **実装は別途承認後**。本タスクでは設計案の提出まで

## 実施しないこと（禁止事項）
- Gold報酬・売値・費用・報酬単価のいかなる数値変更
- NPC調達依頼の報酬調整、Seeder変更
- config/gold.php の削除・変更（コメント追記も案の提出まで）
- items.sell_price の仕様変更
- DB書き込み・migration作成・課金処理変更・デプロイ・コミット
- ダッシュボードの実装

## 確認コマンド案
- `rg -n "equipment_sell_prices" app/ database/ routes/ resources/ config/`
- `rg -n "reward_gold_per_unit|reward_gold_on_complete" app/ database/`
- `php artisan tinker` での読取集計（例）:
  - `NpcProcurementRequestMaterial::selectRaw('min(reward_gold_per_unit), max(reward_gold_per_unit), avg(reward_gold_per_unit)')->first()`
  - `GoldTransaction::where('type','npc_procurement_delivery')->selectRaw("date(created_at) d, sum(amount) s")->groupBy('d')->orderByDesc('d')->limit(14)->get()`
- 生成ロジックの単価決定式は該当Serviceの精読で確認

## 完了条件
- [ ] 調査1の分類結論（正本/初期値用/残骸）と根拠、コメント・docs追記案の提出
- [ ] 調査2の分布数値、時給Gold試算（3帯）、無限錬金経路の有無判定の提出
- [ ] 調査3の設計案（最小変更範囲・type一覧・懸念点つき）の提出
- [ ] DB書き込み・コード変更を一切行っていないことの明記

## 人間確認が必要なポイント
- 本番DBを照会する必要が出た場合（ローカルにデータが少ない場合）の可否
- 調査2で無限錬金経路が見つかった場合の対応優先度（数値変更は別タスクで裁定）
- 調査3の設計案を実装に進めるかの判断
