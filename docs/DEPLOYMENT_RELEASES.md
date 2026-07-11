# リリース切替デプロイ運用

通常デプロイは、公開中のコードを上書きしません。新しいリリースを準備してから `valzeria_current` のシンボリックリンクを原子的に切り替えます。

## 初回移行（必ず全体メンテナンスとDBバックアップ後に実施）

1. Xserver側でDBバックアップを取得し、復元手順を確認する。
2. `valzeria_shared/.deploy_secret` に十分長いランダム値を保存する（Web公開領域外、0600）。
3. `valzeria_shared/.deploy_allowed_ips` にデプロイ元の固定IPを1行ずつ保存する。
4. `server_deploy_api.php` を `public_html` へ手動配置する。旧APIはHMAC署名に対応していないため、この初回配置だけは管理画面/SSH/ファイルマネージャーで行う。
5. ローカルで `VALZERIA_DEPLOY_SECRET` を設定し、`local_deploy.php` を実行する。
6. 初回リリース後、トップ・ログイン・探索・管理画面・ログを確認する。
7. 非公開テストディレクトリで、シンボリックリンク、同一ファイルシステム上のrename、権限、OPcache、cronを確認してから公開切替する。

初回移行中は既存の `valzeria_project` を残したまま、`valzeria_current` から参照します。`storage` と `.env` は既存の実体を共有リンクとして使うため、プレイヤーデータ・アップロード・本番設定を移動または削除しません。

## 通常デプロイ

- `DEPLOY_MIGRATION_MODE=none`（既定）はmigrationを実行しない。
- `DEPLOY_MIGRATION_MODE=backward_compatible` は、旧コードと互換なmigrationだけに使う。
- `DEPLOY_MIGRATION_MODE=maintenance_required` は、全体メンテナンスを許容するDB非互換変更だけに使う。
- Seeder・データ補正・削除処理は通常デプロイで自動実行しない。必要な場合は、対象とロールバックを明示して別途実施する。
- 管理画面限定デプロイは廃止。全体を1リリースとして反映する。
- 署名はタイムスタンプ、nonce、ZIPのSHA-256を含むHMACで検証し、nonceは一度だけ記録する。古い署名・同じnonceは拒否する。
- ZIPは展開前にパス、件数、圧縮率、展開サイズ、シンボリックリンクを検証し、展開後も各ファイルのサイズを検証する。
- Viteの `public/build` は共有アセット領域へ追記だけ行う。旧アセットの削除はデプロイ処理に含めない。

## ステージング

- ステージングは本番とは別のDB・共有 `.env`・`server_deploy_api.php` を使う。本番の `.env`、決済鍵、OAuth設定、ポータル送信設定を複製しない。
- 各リリースは単独で起動するため、送信ZIPにはComposerの `vendor` 一式を含める。`storage` と `.env` は共有領域を使い、ZIPへ含めない。
- サーバーは展開後220MB・16,000ファイルまでの署名付きZIPだけを受け付ける。上限超過時は公開リリースを切り替えず停止する。
- 本番のローカル送信秘密鍵は `VALZERIA_DEPLOY_SECRET` またはリポジトリ直下の `.env.production.local` から読む。後者はGitと送信ZIPから除外される。
- `.env.staging.example` をひな形にする。ローカル側では `VALZERIA_STAGING_DEPLOY_SECRET` を実行環境で設定し、`php local_deploy_staging.php` だけを使う。この入口は `staging.valzeria.com` 以外へ送信しない。未コミット変更を含める時は `STAGING_DEPLOY_ALLOW_DIRTY=1` を明示して、作業中スナップショットとして送信する。
- ステージング初回だけは、共有 `storage` と `.env` を作成した後に `STAGING_DEPLOY_BOOTSTRAP_EMPTY=1` を付けて実行する。空のリリースを `valzeria_current` として初期化するため、本番の `valzeria_project` は参照しない。
- 空DB確認は migration → `db:seed` → `dungeon:validate` の順に実行する。新規ダンジョンの公開手順は `docs/DUNGEON_RELEASE_WORKFLOW.md` を参照する。
- Xserver側の初回設定は `docs/STAGING_SETUP.md` を参照する。ステージングの共有領域は公開フォルダ外の `staging_valzeria_shared` を使い、公開フォルダには `.deploy_staging` マーカーだけを置く。ステージングデプロイAPIと本番デプロイAPIは、秘密鍵・許可IP・共有領域を共有しない。

## ロールバック

コードだけのロールバックは、`valzeria_current` を直前リリースへ原子的に戻すことで行う。DB migrationを実行した場合は、旧コードとDBの互換性が確認できる場合に限る。削除・型変更・大量変換を伴うDB変更は、DBバックアップからの復旧手順を優先する。

切替直後は、`current` の実体、必須ファイル、DB接続を内部ヘルスチェックする。これに失敗した場合は、直前リリースへのリンク復旧を試み、成功・失敗・ロールバック理由を監査ログへ残す。公開側ではトップ、ログイン、探索、管理画面、`storage/logs/laravel.log` を確認する。

## DB復元（障害時のみ。実行前に承認を得る）

1. まず全体メンテナンスへ切り替え、発生時刻・対象リリース・直前のDBバックアップ識別子を記録する。
2. 直前バックアップを**検証用の別DB**へ復元し、接続情報・主要テーブルの件数・ログイン・探索を確認する。本番DBへ直接復元して検証しない。
3. DB変更が後方互換なら、先に `valzeria_current` を旧リリースへ戻し、ヘルスチェックと公開確認を行う。DB復元は不要な場合がある。
4. DB復元が必要な場合だけ、確認済みのバックアップを本番DBへ復元する。復元前に、障害時点の本番DBも追加でバックアップして退避する。
5. 復元後は旧リリースを参照した状態で、DB接続・ログイン・探索・管理画面・`storage/logs/laravel.log` を確認してからメンテナンスを解除する。

DBの復元、migrationの巻き戻し、プレイヤーデータに触れる補正は、いずれも通常デプロイ処理に含めない。対象バックアップと実行者を明示し、個別承認を得て実施する。

## 運用上の注意

- `releases` を自動削除しない。世代整理はDBバックアップと復旧可能性を確認してから手動で行う。
- cronは `scripts/run_current_schedule.php` を使い、開始時に `valzeria_current` の実体パスを固定してから `schedule:run` を実行する。常駐処理も同じ方式へ移す前にXserverの設定を確認する。
- デプロイAPIのIP制限、秘密鍵、共有領域の権限はサーバー側の運用設定であり、リポジトリへ保存しない。
