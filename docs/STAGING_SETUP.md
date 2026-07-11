# staging.valzeria.com 初回設定

目的は、本番のコード・DB・秘密鍵・プレイヤーデータから独立した実プレイ確認環境を作ることです。本番URLや本番の共有領域をこの手順で操作しません。

## Xserver側で用意するもの

1. `staging.valzeria.com` のサブドメインと専用公開フォルダを作成する。
2. 本番と別のMySQLデータベース・ユーザーを作成する。
3. `public_html` の外（`valzeria.com` 直下）に、ステージング専用で次を作成する。`staging.valzeria.com` の公開フォルダ内には置かない。

```text
staging_valzeria_releases/
staging_valzeria_shared/
  .env
  .deploy_secret
  .deploy_allowed_ips
  storage/
  assets/
```

初回デプロイ時に、共有 `storage` 配下のキャッシュ・セッション・ログ用ディレクトリは自動で作成される。
空DBでキャッシュ保存先がDBの場合も、migration完了後にキャッシュを初期化する。

4. `valzeria_shared/.env` は `.env.staging.example` を基にし、`APP_ENV=staging`、ステージングDBだけを設定する。`APP_KEY` には本番と異なる値を必ず設定する。ローカルで `php artisan key:generate --show` を実行して得た値を使い、空欄のまま保存しない。Stripe、Google OAuth、ポータル送信の値は空欄または無効にする。
5. `valzeria_shared/.deploy_secret` に本番と異なるランダム値を保存する（0600）。ローカルでは同じ値を `VALZERIA_STAGING_DEPLOY_SECRET` として設定する。
6. `valzeria_shared/.deploy_allowed_ips` にデプロイ元のグローバルIPだけを1行ずつ設定する。
7. このリポジトリの `server_deploy_api.php` をステージング公開フォルダへ `server_deploy_api.php` として配置し、同じ公開フォルダに空の `.deploy_staging` ファイルを作成する。このマーカーでステージング専用の共有領域を選ぶ。

## 初回リリース

コミットは必須ではありません。作業中の変更を含めて確認する場合は、明示的に `STAGING_DEPLOY_ALLOW_DIRTY=1` を設定してから実行します。

ローカル側の秘密鍵は、環境変数の代わりにリポジトリ直下の `.env.staging.local` へ次の1行で保存できます。このファイルはGitとデプロイZIPから除外されるため、チャットへ秘密鍵を送る必要はありません。

```text
VALZERIA_STAGING_DEPLOY_SECRET=<staging secret>
```

```powershell
$env:STAGING_DEPLOY_BOOTSTRAP_EMPTY = '1'
$env:STAGING_DEPLOY_ALLOW_DIRTY = '1'
& 'C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64\php.exe' local_deploy_staging.php
```

`local_deploy_staging.php` は、未コミットの変更がある場合、またはURLが `staging.valzeria.com` 以外の場合に送信を中止します。ただし `STAGING_DEPLOY_ALLOW_DIRTY=1` を明示した時だけ、現在の作業ツリーをステージング用スナップショットとして送信します。初回成功後は `STAGING_DEPLOY_BOOTSTRAP_EMPTY` を設定しません。

## 初回データ投入と確認

初回の `STAGING_DEPLOY_BOOTSTRAP_EMPTY=1` では、ステージング専用DBを `db:wipe` で初期化してからmigrationと `DatabaseSeeder` によるマスタ投入を自動実行する。古いmigrationの参照順は初回中だけ外部キー検査を一時停止し、完了後に再有効化する。通常デプロイではSeederやデータ補正を実行しない。この初期化フラグは本番APIでは拒否される。初回後に `php artisan dungeon:validate` を確認する。

既存ステージングDBを最新のマスタデータで作り直す必要がある場合だけ、明示的に `STAGING_DEPLOY_RESET_DATABASE=1` を設定する。この操作はステージングのプレイヤー・戦闘・運営設定をすべて消去してmigrationとSeederを再実行する。本番APIは拒否するため、本番DBを初期化することはできない。

```powershell
$env:STAGING_DEPLOY_RESET_DATABASE = '1'
$env:STAGING_DEPLOY_ALLOW_DIRTY = '1'
& 'C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64\php.exe' local_deploy_staging.php
```

XserverのPHPアップロード上限が不安定でZIPを受信できない場合は、`DEPLOY_BUILD_ONLY=1` で生成した `deploy_temp.zip` を `staging_valzeria_shared/manual_upload/staging_deploy.zip` としてFile Managerでアップロードする。その後、ローカルで `local_deploy_staged_zip.php` を実行する。この経路はステージング専用で、ZIP本文のハッシュを署名検証してから共有領域のZIPを展開し、成功・失敗後ともアップロード済みZIPを削除する。

その後、ステージング専用のメール登録アカウントで新規キャラを作成し、街表示・通常探索・ボス挑戦・変更対象の画面を実プレイで確認する。

## 通常のステージングリリース

```powershell
$env:STAGING_DEPLOY_ALLOW_DIRTY = '1' # コミット前の変更も確認する場合だけ
& 'C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64\php.exe' local_deploy_staging.php
```

コミット済みの内容だけを送る場合は `STAGING_DEPLOY_ALLOW_DIRTY` を設定しません。本番へ反映する時は、検証済みの変更範囲を確認してから既存の `local_deploy.php` を別途実行する。ステージング用秘密鍵やURLを本番用環境変数へ設定しない。
