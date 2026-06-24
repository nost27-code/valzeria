# 戦闘システム 本格実装タスクリスト

- [x] DBマイグレーションとモデルの準備
  - [x] `enemies` テーブルに `job_exp_reward` (int, default 0) カラムを追加
  - [x] `skills` と `job_skills` テーブルの作成
  - [x] `Skill`, `JobSkill` モデルの作成
  - [x] `Enemy` モデル等への `$fillable` の追加

- [x] バトル用クラス群の新規作成
  - [x] `app/Services/Battle/BattleActor.php`
  - [x] `app/Services/Battle/BattleState.php`
  - [x] `app/Services/Battle/DamageCalculator.php` （命中・回避・クリティカル含む）
  - [x] `app/Services/Battle/BattleResult.php`

- [ ] スキル処理の基盤作成
  - [ ] `app/Services/Battle/SkillService.php`
  - [ ] スキルの初期データ（Seeder）作成

- [x] 戦闘ロジックの本格化
  - [x] 魔法ダメージ計算式の実装
  - [x] 命中・回避の判定ロジックの実装
  - [x] クリティカル判定のロジックの実装

- [x] メインロジック（BattleService & 報酬）のリファクタリング
  - [x] `BattleService::executeBattle` の全面改修（BattleActor等を利用した進行）
  - [x] `LevelService::addRewardAndCheckLevelUp` に職業経験値の付与とレベルアップ/マスター処理を統合
  - [x] `ExplorationService` で戦闘後の職業経験値を処理

- [ ] テストと調整
  - [ ] Tinker または UI で実際に戦闘を行い、ログの出力や勝敗判定、経験値付与が正しく機能するか確認
  - [ ] `job_exp_reward` の動作確認
