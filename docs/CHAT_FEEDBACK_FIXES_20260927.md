# チャットで挙がった4件の改善・検証記録

対象基点: `0aa3f11594df9c4369e170760d9ac26b055ee012`（調査時の本番・origin/main）。既存の未コミット作業を保護するため、`codex/chat-feedback-fixes-20260927` の独立worktreeで実装。本番公開・本番データ更新は未実施。

## 変更内容と対象

| 対象 | 現状・原因と変更 | 主なファイル |
|---|---|---|
| 探索の保存競合 | Character行ロックの後に未登録操作IDをFOR UPDATE検索していた。REPEATABLE READのInnoDBでは別Characterの新規INSERTを妨げるgap lockが生じる。操作ID検索を通常SELECTにし、Character行ロック・unique制約・報酬と操作記録の同時commit・例外時rollbackを維持。自動再戦は追加しない。 | `app/Http/Middleware/CommitExplorationRequest.php` |
| 設定cacheのロック待ち | DB cacheは期限切れの読取でも削除・再作成する。transaction中かつDB cache利用時はgame_settingsを直接読む。transaction外の5分cacheと設定変更時の無効化は維持。 | `app/Services/GameSettingService.php` |
| 装備の兆し | 地図院・公開地図一覧・詳細で、地図生成時に保存された武器/防具/装飾品の確率上乗せを開閉式の説明として表示。確率ポイントの意味と、毎回の入手・高ランクの保証ではないことを説明。現在の等級configから既存地図の補正を推測しない。 | `app/Services/ExplorationMapDisplayService.php`, `resources/views/exploration-maps/{index,published,show}.blade.php`, `resources/views/exploration-maps/partials/equipment-bonus.blade.php` |
| 転職後の保存プリセット | 保存時の職業と現在職の一致だけを要求する制限を撤廃。表示と適用は既存の全戦技使用条件・枠・Cost・奥義制限に従う。使用不可なら全体を拒否し、現在構成・保存済み構成を保持。通常/ボス/PvP/レイドに対応。 | `app/Services/JobArtPresetService.php`, `resources/views/job-arts/partials/presets.blade.php` |
| 進化合成からの装備保護 | 既存の保護POSTを利用し、その場で保護/解除。個体IDで同じ装備の表示を同期し、保護時は売却を無効化・一括売却選択を解除。解除後は売却確認を利用可能。二重送信を抑止。通常POST時も合成画面へ戻す。所有権・市場出品中の拒否は維持。 | `app/Http/Controllers/EquipmentController.php`, `resources/views/smith/index.blade.php`, `resources/views/smith/_evolution_detail.blade.php`, `resources/views/smith/partials/equipment-lock.blade.php` |

DBスキーマ・migration・ドロップ率・価格・報酬額の変更なし。メール設定・購入処理は今回の対象外。

## 検証

- PHPUnit: **71 tests / 693 assertions 成功**。`JobArtPresetTest`, `GameSettingTransactionCacheTest`, `ExplorationMapEquipmentDescriptionTest`, `PublicReadinessSafetyTest`, `EquipmentWarehouseBulkSaleTest`, `MapExplorationBatchServiceTest`, `EquipmentEvolutionServiceTest`, `SmithBankPaymentViewTest`, `ExplorationMapRewardProfileTest`。
- 保存プリセットの全4contextへの転職後適用、使用不可時の全体拒否、既存Cost・未習得・他Character拒否を確認。
- 地図の保存済み小数補正が勝利時のDropServiceへ渡ることと、現行等級設定に置換せず表示することを確認。
- 期限切れDB cacheへのアクセスをtransaction内で行わず、rollbackした設定値が共有cacheに漏れないことを確認。
- 独立したローカルMySQL 8.4.3 / InnoDB / REPEATABLE READで、実際のMiddlewareを別プロセスから同時実行。
  - 旧FOR UPDATEを再現した対照群: 別Characterの片方が1213 deadlockで失敗。
  - 修正後: 別Characterが両方成功。各報酬は1回。
  - 同一Character・同一tokenの同時再送: 両方同じ保存結果へ戻り、報酬は1回だけ。
  - 他接続が期限切れ設定cache行をロック中: 修正後のtransaction内設定読取が待機せず成功。
- 再現用: `scripts/verify/exploration-lock-concurrency.php`。専用ローカルポート13327、`APP_ENV=testing`と新規`DB_DATABASE=valzeria_lockcheck_<unique>`が必須。既存DBは再利用・削除しない。
- Chromiumの390px/1280pxで、実Blade部品・Livewire/Alpine・ビルド済み共通JSを使い、保護/解除、連打、同じ個体の複数表示同期、売却無効化・解除後の売却確認、一括選択解除、地図説明開閉を確認。API応答は試験用。横はみ出し・ブラウザー例外なし。
- `npm run build`、`php artisan view:cache`、変更PHPの構文確認、`git diff --check` 成功。

## 確認手順と残る範囲

1. 2プレイヤーで同時探索し、結果保存・報酬・HP/SP・探索力を確認。同じ操作の再送でも追加消費・追加報酬がないことを確認。
2. 装備の兆しの地図を地図院/公開一覧/詳細で開き、その地図の保存値を表示することを確認。
3. プリセット保存後に転職し、使用条件を満たす構成は適用、不適合は理由表示と現構成維持を確認。
4. 進化合成の同一個体で保護/解除し、売却・一括売却の状態と、進化後の従来の保護継承を確認。

本番はMariaDB 10.5.26 / REPEATABLE READと確認済み。ただしPROCESS権限がなく過去デッドロックの完全な待機グラフは取得できなかった。本番の全ロックエラーが同一原因とは断定していない。MariaDB上での修正後並行試験、認証済み本番画面、スマホ実機、公開後の再発有無は未確認。

`docs/DOMAIN_RULES.md`, `docs/DATA_MODEL.md`, `docs/UPDATE_LOG.md`, `config/admin_update_summaries.php`を同期済み。
