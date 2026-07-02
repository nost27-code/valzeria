# 修正指示: Gold正本コメント・ドキュメント整理（依頼書C）

Status: 未着手（人間確認後にCodexへ渡す）

## 目的
装備売却価格の実行時正本を明確にし、Codexや開発者が `config/gold.php` の `equipment_sell_prices` を現行売却処理の正本として誤読しないようにする。

## 背景
- TASK_B_GOLD_ECONOMY_INVESTIGATION の読取専用調査で、装備売却時に参照される値は `items.sell_price` であると確認した。
- `config/gold.php` の `equipment_sell_prices` は、現行の売却処理からは参照されておらず、初期投入・移行用の基準表として扱う。
- NPC調達報酬は、素材のNPC売却価格より高くなること自体は暫定許容する。ただし、Gold発行源として継続監視対象とする。

## 現状の問題
- `items.sell_price` と `config/gold.php` の `equipment_sell_prices` が並存しており、正本が曖昧に見える。
- 将来の修正で、Codexが `config/gold.php` 側を現行売却価格の正本として扱うと、実際の売却挙動とドキュメント判断がずれる。
- NPC調達報酬はGold発行源として重要だが、監視対象であることが明文化されていない。

## 直すこと
- `items.sell_price` が装備売却の実行時正本であることを明記する。
- `config/gold.php` の `equipment_sell_prices` は初期投入・移行用の基準表であり、現行の売却処理の正本ではないことを明記する。
- NPC調達報酬はGold発行源として監視対象であることを、`docs/DOMAIN_RULES.md` または `docs/dev-os` 配下の適切な文書へ記録する。
- 追記する文面は、既存文書の粒度に合わせて短く明確にする。

## 触らないこと
- Gold報酬・費用・売値の数値変更は行わない。
- Seeder変更は行わない。
- `config/gold.php` の配列値は変更しない。
- `items.sell_price` の仕様変更は行わない。
- DB変更、migration作成、DB書き込みは行わない。
- 課金処理、輝石、有償/無償残高、Stripe、`kiseki_transactions` には触れない。
- デプロイしない。
- コミットしない。
- 本番DB照会は行わない。
- 依頼範囲外のリファクタリング・文言変更は行わない。

## 変更範囲
- 想定ファイル:
  - `docs/DOMAIN_RULES.md`
  - `config/gold.php`（コメント追記が最小で有効な場合のみ）
  - 必要に応じて `docs/dev-os/GOLD_ECONOMY_REVIEW_2026-07-02.md`
- 想定しない範囲:
  - `app/Services/GoldService.php`
  - `database/seeders/*`
  - `database/migrations/*`
  - `items.sell_price` の値や仕様
  - NPC調達報酬の単価・数量・生成ロジック

## 既存仕様への影響
- Gold: 市場、素材交換所、鍛冶費用、宿代、銀行、倉庫Gold拡張、総資産番付、換金品売値、救済宿泊の発生率に関連する。ただし本タスクはコメント・ドキュメント整理のみで、Goldの発行量・回収量・売却挙動は変更しない。
- 装備: `items.sell_price` が装備売却時の実行時正本であることを明文化するのみで、装備性能・ドロップ・進化・強化・売却額は変更しない。
- 素材・ドロップ: NPC調達報酬を監視対象として記録するのみで、素材ドロップ率・市場・素材交換・調達依頼の数値は変更しない。

## DB変更
- なし。

## 管理者機能への影響
- なし。

## ログ・更新履歴への影響
- 公開ログ: なし。
- `gold_transactions`: なし。
- `config/admin_update_summaries.php`: 不要。コメント・ドキュメント整理のみでプレイヤー可視挙動は変わらないため。
- docs同期: 本タスク自体が docs 同期・コメント整理。実装状態の変更はない。

## セキュリティ注意点
- 本番DB照会は行わない。
- 課金処理や輝石関連ファイルには触れない。
- 内部ID、秘密情報、プレイヤー個人情報を追記しない。

## パフォーマンス注意点
- なし。コメント・ドキュメント整理のみ。

## 確認手順
1. `rg -n "equipment_sell_prices|items.sell_price|NPC調達|Gold発行源|監視対象" docs config/gold.php` を実行し、追記箇所が確認できること。
2. `git diff -- docs/DOMAIN_RULES.md docs/dev-os/GOLD_ECONOMY_REVIEW_2026-07-02.md config/gold.php` を確認し、数値・配列値・ロジック変更が含まれていないこと。
3. `php -l config/gold.php` を実行する。`config/gold.php` を変更していない場合は不要理由を報告する。

## DB変更: なし

## 更新サマリ
不要。プレイヤー可視挙動・管理画面挙動・バランス値は変更しないため。

## 完了条件
- [ ] `items.sell_price` が装備売却の実行時正本であることが明記されている。
- [ ] `config/gold.php` の `equipment_sell_prices` は初期投入・移行用の基準表であり、現行売却処理の正本ではないことが明記されている。
- [ ] NPC調達報酬はGold発行源として監視対象であることが記録されている。
- [ ] 数値変更、ロジック変更、config配列変更、Seeder変更、DB変更、migration作成、課金処理変更がない。
- [ ] デプロイ・コミット・本番DB照会を行っていない。
- [ ] 実行した確認コマンドと結果を報告している。

## 完了後に報告すること
- 変更ファイル一覧と各ファイルの変更要約
- 実行した確認コマンドと結果
- `equipment_sell_prices` / `items.sell_price` / NPC調達監視文言の確認結果
- 数値変更・ロジック変更・DB変更がないこと
- Docs update / Admin update summary の判断
- 未解決事項
