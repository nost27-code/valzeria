# ヴァルゼリアの冒険者 AI開発ルール（統合済み）

> **2026-07-07統合**: この文書は AGENTS.md と docs/dev-os/ に統合済みです。**新規記載禁止**。
> 新しいルールの追記先は AGENTS.md または docs/dev-os/、恒久的なゲームルールは docs/DOMAIN_RULES.md です。

## 旧節の移設先

| 旧節 | 移設先 |
|---|---|
| 実装時に必ず参照するファイル | docs/CODEMAP.md |
| Gold経済のルール | docs/DOMAIN_RULES.md「Economy」 |
| 既存仕様を勝手に変更しないルール | AGENTS.md「Work rules」 |
| 破壊的変更の事前明示ルール | AGENTS.md「Human approval required」 |
| マスタデータ変更時の確認項目 | docs/dev-os/QA_CHECKLIST.md「追加QA: マスタデータ変更に触れる場合」 |
| 戦闘ロジック変更時の確認項目 | docs/dev-os/QA_CHECKLIST.md「追加QA: 戦闘ロジックに触れる場合」 |
| 課金・輝石まわりの注意点 | docs/DOMAIN_RULES.md「Economy」+ docs/dev-os/QA_CHECKLIST.md「段階3」 |
| 管理者機能・不正対策まわりの注意点 | docs/DOMAIN_RULES.md「Economy」+ docs/dev-os/QA_CHECKLIST.md「段階3」 |
| 実装後に確認すべきテスト・チェック項目 | docs/dev-os/QA_CHECKLIST.md |
| Codexが作業完了時に報告すべき形式 | AGENTS.md「Final response format」 |

正仕様の優先順位は AGENTS.md「Source of truth」節に従う（コード > DOMAIN_RULES.md > AI_CONTEXT.md > その他docs）。
