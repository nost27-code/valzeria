# 実装指示書: 技説明⇔実装の整合バリデータ拡張（TASK_E/F後に実施）

## 目的
今回の乖離（説明文が実装に無い効果を謳う）の再発を、Seeder投入前バリデーション＋ユニットテストで機械的に防ぐ。

## 現状の問題
`app/Support/JobArtMasterValidator.php` は「ダメージ/Gold/Drop」の文言チェックのみで、今回発覚した乖離（デバフ対象ステータス・ターン表記・Hit数・未実装メカニクス言及）を検出できない。

## 実装対象
`JobArtMasterValidator::validateRows()` に以下のルールを追加（違反はproblemsに追加）:

1. **禁止ワード（未実装メカニクス）**: memoに以下を含む行はエラー
   - `ターン`（「戦闘中」表記へ誘導するメッセージ）
   - `反撃` / `継続ダメージ` / `解除` / `状態異常`
   - `命中率` `回避率` `会心率`（上昇・低下の文脈のみ。※「命中率に影響」は許可リストで除外）
2. **デバフ整合**: memoが `ATK.{0,6}低下` にマッチ → その行の `enemy_atk_down_percent > 0` が必須。同様に `MAG低下`→enemy_mag_down、`SPD低下`→enemy_spd_down、`DEF低下|守り.{0,4}低下`→enemy_def_down
3. **Hit数整合**: memoの `(\d)回` にマッチする数字が、行の `hit_count`（未指定ならカタログ既定）と一致すること
4. **回復整合**: memoが `HP.{0,6}回復|最大HPの\d+%回復` にマッチ → テンプレが HEAL/HEAL_CLEANSE または `heal_percent > 0` または `drain_hp_rate > 0` が必須
5. **軽減整合**: memoが `被ダメ.{0,8}軽減` にマッチ → テンプレが GUARD_BARRIER/DAMAGE_GUARD_BARRIER または `damage_reduction_percent > 0` が必須
6. **吸収整合**: memoが `吸収` にマッチ → テンプレDRAIN かつ（`drain_hp_rate > 0` または `mp_recover_percent > 0`）が必須

必殺技側にも同等の検査を新設: `JobSpecialSkillValidator`（新規クラス、`database/data/job_special_skills.php` を対象）
- description の `LUKに応じて` → `luk_power_rate > 0`
- `確率で追加` → `extra_hit_chance_percent > 0`
- `最高火力|高い方` → `hybrid_scaling === 'max'`
- `レア判定UP` → `rare_bonus_percent > 0`
- `n回` → `hit_count == n`
- `X%回復` → `heal_percent == X`（HP文脈）/ `mp_recover_percent == X`（SP文脈）
- `X%低下` → 対応する enemy_*_down_percent == X
- `X%無視` → `def_ignore_percent == X`
- `X倍` → `power_multiplier == X`（誤差0.01許容）

## テスト追加
- `tests/Unit/JobArtEffectCatalogTest.php` に「現行job_arts.jsonが新ルール全通過」のアサーションを追加（既存 `test_current_job_art_json_has_no_memo_template_mismatch` が拡張ルールで通ること）
- 新規 `tests/Unit/JobSpecialSkillValidatorTest.php`: 現行 `job_special_skills.php` 全行が通過すること＋違反サンプル（例: memoに「3ターン」）が検出されること
- `app/Console/Commands/ValidateJobArts.php` から新ルールが実行されることを確認

## 実装対象外
- エンジン・データの変更（TASK_E/Fの範囲）
- バリデーション以外のリファクタリング

## DB変更: なし

## 完了条件
- [ ] 新ルールで `php artisan test` 全通過（＝現行データが全ルール準拠であることの証明）
- [ ] 意図的にmemoへ「3ターン」を入れるとテストが落ちることを確認して戻す
- [ ] docs同期: dev-os/QA_CHECKLIST.md に「技のmemo/description変更時は validator テスト実行」を追記

## 更新情報サマリ案
- category: internal
- title: 技データの整合チェックを強化
- detail: （プレイヤー向け表示は不要。internalのため掲載しない判断でも可）
