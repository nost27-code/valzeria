# 伝説職解放タスク一覧

- [ ] `docs/add_legend_jobs_and_bosses/` ディレクトリの作成およびドキュメント保存
- [ ] フェーズ1の実装
  - [ ] `database/seeders/ItemSeeder.php` の更新（英雄の証など5つのアイテム追加）
  - [ ] `database/seeders/AllDungeonsSeeder.php` の更新（4つの裏ダンジョン追加）
  - [ ] `database/seeders/BossSeeder.php` の更新（アビスロードなど4体のボス追加）
  - [ ] `database/seeders/EnemyDropsSeeder.php` の更新（各ボスのアイテムドロップ設定）
- [ ] フェーズ2の実装
  - [ ] `database/seeders/JobSystemSeeder.php` の更新（伝説職の `JobRequirement` にアイテムを追加）
- [ ] フェーズ3の実装
  - [ ] `areas` テーブルに `required_master_job_keys` カラムを追加するマイグレーション作成・実行
  - [ ] `app/Models/Area.php` に解放条件判定メソッド (`isUnlockedFor`) の追加
  - [ ] ダンジョン一覧UI等でのロック表示対応
- [ ] 動作確認とシードデータの反映
- [ ] `walkthrough.md` の作成
