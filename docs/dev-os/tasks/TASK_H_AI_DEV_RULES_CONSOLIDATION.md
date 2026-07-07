# 実装指示書: docs/ai_development_rules.md の統合・リダイレクト化

## 目的

AIエージェントが読むルール文書を AGENTS.md + docs/dev-os/ の2系統に一本化し、古い記述（MP表記・valzeria_spec正仕様扱い）がエージェントの誤動作を引き起こすリスクを除去する。

## 背景

- docs/dev-os/AGENTS_MD_PROPOSAL.md（2026-07-02適用済み）の適用手順2で予定されていた統合作業の残り。
- ai_development_rules.md の冒頭には既にリダイレクト注記があるが、本文約170行が残存しており、AGENTS.md / QA_CHECKLIST.md / DOMAIN_RULES.md と三重管理状態。
- 正仕様チェーンは AGENTS.md「Source of truth」節が正: コード > DOMAIN_RULES.md > AI_CONTEXT.md > その他docs。

## 現状の問題

ai_development_rules.md に現行裁定と矛盾する記述が残っている:

1. 49行目付近「ステータス表記は HP / **MP** / ATK / ...」→ 2026-07-02裁定の正表記は **SP**（MP/STR/AGIは旧表記）。
2. 11行目「正仕様: `docs/valzeria_spec.md`」→ valzeria_spec は歴史的参照に格下げ済み。
3. 各節の内容が AGENTS.md（Work rules / Human approval / Final response format）や docs/dev-os/QA_CHECKLIST.md と重複し、片側だけ更新される事故の温床。

## 実装対象

1. **吸収確認**: ai_development_rules.md の各節について、移設先に同等の内容が存在するか確認する。欠けている項目だけを移設先へ**最小差分で追記**する。対応表:

   | ai_development_rules.md の節 | 移設先 | 備考 |
   |---|---|---|
   | 実装時に必ず参照するファイル | docs/CODEMAP.md | CODEMAPに載っていないファイル参照のみ追記 |
   | Gold経済のルール | docs/DOMAIN_RULES.md | 恒久ルールとして |
   | 既存仕様を勝手に変更しないルール | AGENTS.md「Work rules」 | ほぼ吸収済みのはず。欠けだけ確認 |
   | 破壊的変更の事前明示ルール | AGENTS.md「Human approval required」 | 吸収済みのはず |
   | マスタデータ変更時の確認項目 | docs/dev-os/QA_CHECKLIST.md | |
   | 戦闘ロジック変更時の確認項目 | docs/dev-os/QA_CHECKLIST.md | |
   | 課金・輝石まわりの注意点 | DOMAIN_RULES.md（ルール）+ QA_CHECKLIST.md（確認項目） | |
   | 管理者機能・不正対策まわりの注意点 | 同上 | |
   | 実装後に確認すべきテスト・チェック項目 | docs/dev-os/QA_CHECKLIST.md | |
   | Codexが作業完了時に報告すべき形式 | AGENTS.md「Final response format」 | 吸収済みのはず |

2. **表記の是正**: 移設の際、旧表記（MP/STR/AGI、valzeria_spec正仕様扱い）は現行裁定（SP/ATK/SPD、Source of truth order）へ直してから移す。**旧表記のまま移設しない。**
3. **リダイレクト化**: 吸収完了後、ai_development_rules.md の本文を削除し、以下だけ残す:
   - 「この文書は AGENTS.md と docs/dev-os/ に統合済み（2026-07-07）。新規記載禁止」
   - 移設先への対応表（上表の簡略版）
4. **参照の張り替え**: リポジトリ内で `ai_development_rules` を参照している箇所（grep で洗い出す。docs/、AGENTS.md、.claude/ 等）を新しい参照先に更新する。

## 実装対象外（重要）

- docs/valzeria_spec.md、docs/project_knowledge.md には触らない。
- DOMAIN_RULES.md / QA_CHECKLIST.md の**既存記述の書き換え・再構成はしない**（欠落分の追記のみ）。
- ai_development_rules.md のファイル削除はしない（リダイレクトスタブとして残す。外部からのリンク切れ防止）。
- ゲームコード（app/ 以下）には一切触らない。
- 数値バランス・ゲーム仕様の変更・新規裁定は行わない。移設中に矛盾を発見したら**勝手に裁定せず「要裁定」として報告**する。

## DB変更: なし

## 完了条件

- [ ] ai_development_rules.md がリダイレクトスタブ（30行以内）になっている。
- [ ] 上表の全節について「吸収済み/追記した/要裁定」のいずれかが報告されている。
- [ ] 移設後の文書に MP/STR/AGI 表記が新規混入していない（`grep` で確認）。
- [ ] `ai_development_rules` への参照が全て更新されている（grep 結果ゼロまたは意図的な残置の説明あり）。
- [ ] docs同期: docs/dev-os/README.md の「次に整備すべきタスク」3番を完了扱いに更新。

## 更新情報サマリ: 不要（AI向けdocs整理のみ、プレイヤー/運営向け挙動変更なし）
