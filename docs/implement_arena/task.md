# 闘技場システム実装タスク

- [x] 1. マイグレーションの作成
  - [x] `arena_rankings` テーブル（character_id, rank, wins, losses）
  - [x] `arena_logs` テーブル（attacker_id, defender_id, is_attacker_win, attacker_old_rank, attacker_new_rank, defender_old_rank, defender_new_rank, created_at）
- [x] 2. モデルの作成
  - [x] `ArenaRanking` モデル
  - [x] `ArenaLog` モデル
  - [x] `Character` モデルへのリレーション追加
- [x] 3. バトルロジックの実装
  - [x] `PvPBattleService` の作成
  - [x] バトル計算とログ記録、順位変動ロジックの実装（勝利時に自分の順位が1つ上がり、元々その順位にいた人が1つ下がる）
- [x] 4. UIの実装（Livewire）
  - [x] `ColosseumScreen` コンポーネントの作成
  - [x] `colosseum-screen.blade.php` の作成
  - [x] メニューからの遷移設定（または既存の `Location` 等への組み込み）
- [ ] 5. 検証とテスト
  - [ ] 闘技場初参加時の初期ランク付与テスト
  - [ ] ランダムマッチとバトル進行テスト
  - [ ] 順位変動とログ記録の確認
