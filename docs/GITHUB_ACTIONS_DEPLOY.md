# GitHub Actions + SSH デプロイ

この方式では、公開PHPのデプロイAPI、File Manager経由のZIPアップロード、ローカルのデプロイ秘密鍵を使わない。GitHub Actionsがビルド済みリリースをSSH/SCPで非公開領域へ転送し、SSH上のリリーススクリプトがmigration・キャッシュ生成・`current` 切替を行う。

## 安全設計

- `staging` と `production` はGitHub Environmentsを分け、同名でも別のSecretsを登録する。
- 本番ワークフローは手動実行のみで、`deploy-production` の確認入力とGitHub Environmentの承認を両方必要にする。
- SSH接続先のホスト鍵は `SSH_KNOWN_HOSTS` に固定し、接続時に取得して信頼する方式を使わない。
- Actionsは登録した秘密鍵だけを使う非対話SSH接続を先に検証してから、アップロードやDB操作へ進む。
- ステージングと本番でSSH鍵を分け、GitHub Environmentごとに対応する秘密鍵だけを保存する。同一XserverのSSHユーザーを使う限り、鍵だけでサーバー上のフォルダ権限は分離されないため、本番の実行制御はGitHub Environmentの承認と本番専用ワークフローで行う。
- アーカイブはWeb公開領域外の `deploy-incoming` に置き、展開・migrationが成功してから `*_current` を原子的に切り替える。

## Xserver側の初回準備

1. サーバーパネルでSSHを有効化する。
2. ステージング用と本番用に、それぞれSSH鍵ペアを作る。秘密鍵はGitHub Environment Secretだけに保存し、チャットやリポジトリへ置かない。
3. 各公開鍵をXserverのSSH公開鍵設定へ登録する。同一SSHユーザーでは両鍵とも同じサーバー権限になるため、鍵の分離はローテーション・監査・GitHub側のSecret分離のために行う。
4. `valzeria.com` の直下に、SSHログインユーザーが書き込める `deploy-incoming` を作る。既存の `staging_valzeria_shared` / `valzeria_shared`、`*_releases`、`*_current` はそのまま使う。
5. `staging.valzeria.com` と本番の共有 `.env`、共有 `storage`、公開フォルダは従来どおり分離して保つ。

## GitHub Environments とSecrets

GitHubリポジトリの **Settings → Environments** で `staging` と `production` を作る。`production` にはRequired reviewersを設定する。

両Environmentへ、その環境専用の値を登録する。

| Secret | 内容 |
|---|---|
| `SSH_HOST` | XserverのSSHホスト名 |
| `SSH_PORT` | Xserverで指定されたSSHポート |
| `SSH_USER` | SSHユーザー名 |
| `SSH_PRIVATE_KEY` | 対象環境専用の秘密鍵全文 |
| `SSH_KNOWN_HOSTS` | `ssh-keyscan -p <port> <host>` の結果を別経路で照合した値 |
| `DEPLOY_ROOT` | 例: `/home/<server-user>/valzeria.com` |
| `DEPLOY_PHP_BINARY` | XserverのPHP 8.4実行パス。空欄時は `php` |

`DEPLOY_ROOT` は公開フォルダではなく `valzeria.com` 自体を指定する。ワークフローは対象に応じて `public_html` または `public_html/staging.valzeria.com`、`valzeria_*` または `staging_valzeria_*` だけを扱う。

## 実行方法

- ステージング: Actionsの **Deploy staging** を開き、確認したいGit refとmigration modeを選んで実行する。
- 本番: Actionsの **Deploy production** を開き、確認欄へ `deploy-production` と入力する。GitHub Environmentの承認後、`main` のみを反映する。

通常のコード変更は、ステージングで実プレイ確認した後に本番ワークフローを明示実行する。Seeder・DB全消去・既存プレイヤー向けのデータ補正は、このワークフローに含めない。

ステージングを空DBへ戻す必要があるときだけ、Actionsの **Reset staging database** を手動実行し、確認欄へ `reset-staging-database` と入力する。このワークフローは `staging_valzeria_current` だけを対象に、`db:wipe` → `migrate` → `db:seed` → `dungeon:validate` を実行する。本番DBには接続しない。

## 初回切替とロールバック

最初のSSHデプロイ前に、公開フォルダに通常ファイルとして残っている `images` / `build` / `storage` を確認する。リリーススクリプトは既存の画像・ビルド資産を削除せず、必要なファイルを上書き追加する。`storage` が通常ディレクトリの場合は自動で置き換えないため、既存運用を確認してから共有 `storage/app/public` へのリンクへ移行する。

コード切替後の失敗時は、`*_current` を直前リリースへ戻せる。実行済みmigrationは自動で戻さないため、非互換migrationやデータ変更は既存のバックアップ・承認手順を使う。

## 旧方式の扱い

`server_deploy_api.php` と `local_deploy*.php` は、SSH経路でステージング・本番の初回リリースとスモークテストが成功するまで削除しない。成功後に秘密鍵・許可IP・公開APIを撤去する計画を別途実施する。
