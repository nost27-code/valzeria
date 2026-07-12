# GitHub Actions + SSH デプロイ

この方式では、公開PHPのデプロイAPIやFile Manager経由のZIPアップロードを使わない。GitHubホステッドRunnerがリリースをビルドし、このPCのリポジトリ専用セルフホストRunnerが成果物を受け取ってXserverへSSH/SCP転送する。SSH上のリリーススクリプトがmigration・キャッシュ生成・`current` 切替を行う。

## 安全設計

- ビルドはGitHubホステッドRunner、SSH転送だけをこのリポジトリ専用のWindowsセルフホストRunnerで行う。
- ステージングは任意refを実行せず、信頼済みの `main` だけをビルド・転送する。
- `staging` と `production` はGitHub Environmentsを分け、同名でも別の接続先Secretsを登録する。
- 本番ワークフローは手動実行のみで、`deploy-production` の確認入力とGitHub Environmentの承認を両方必要にする。
- SSH秘密鍵と検証済み `known_hosts` はこのPCの実行ユーザー配下だけに置き、GitHub Secretsへ保存しない。
- Actionsは対象環境のローカル秘密鍵だけを使う非対話SSH接続を先に検証してから、アップロードやDB操作へ進む。
- ステージングと本番でSSH鍵を分ける。同一XserverのSSHユーザーを使う限りサーバー上のフォルダ権限は分離されないため、本番の実行制御はGitHub Environmentの承認と本番専用ワークフローで行う。
- アーカイブはWeb公開領域外の `deploy-incoming` に置き、展開・migrationが成功してから `*_current` を原子的に切り替える。

GitHubホステッドRunnerからXserverへの直接SSHは、Xserver側から接続を閉じられることを実機確認済み。このためセルフホストRunnerはビルド用途ではなく、接続可能なこのPCからの転送用途だけに限定する。

## Xserver側の初回準備

1. サーバーパネルでSSHを有効化する。
2. ステージング用と本番用に、それぞれSSH鍵ペアを作る。秘密鍵はこのPCの実行ユーザー配下だけに保存し、チャット・GitHub・リポジトリへ置かない。
3. 各公開鍵をXserverのSSH公開鍵設定へ登録する。同一SSHユーザーでは両鍵とも同じサーバー権限になるため、鍵の分離はローテーション・監査・ワークフロー分離のために行う。
4. `valzeria.com` の直下に、SSHログインユーザーが書き込める `deploy-incoming` を作る。既存の `staging_valzeria_shared` / `valzeria_shared`、`*_releases`、`*_current` はそのまま使う。
5. `staging.valzeria.com` と本番の共有 `.env`、共有 `storage`、公開フォルダは従来どおり分離して保つ。
6. Googleログインを使う場合は、ステージング共有 `.env` に `GOOGLE_CLIENT_ID`、`GOOGLE_CLIENT_SECRET`、`GOOGLE_REDIRECT_URI=https://staging.valzeria.com/auth/google/callback` を設定し、Google Cloud ConsoleのOAuthクライアントにもこのURLを許可済みリダイレクトURIとして追加する。本番用の `https://valzeria.com/auth/google/callback` は残す。

## セルフホストRunner

GitHubリポジトリの **Settings → Actions → Runners** からWindows x64 RunnerをこのPCへ登録する。ワークフローは標準の `self-hosted` / `Windows` / `X64` ラベルを使う。初回は対話実行の `run.cmd` で動作確認する。サービス化する場合は、SSH鍵と `known_hosts` を持つWindowsユーザーで動かす。

このRunnerはValzeriaリポジトリ専用とし、Pull Requestや任意ブランチのコードを実行するworkflowには割り当てない。PCが停止中はdeployジョブが待機し、公開状態は変わらない。

ローカルで使用するファイルは次のとおり。

- `C:\Users\yuta\.ssh\valzeria_staging_deploy`
- `C:\Users\yuta\.ssh\valzeria_production_deploy`
- `C:\Users\yuta\.ssh\known_hosts`

## GitHub Environments とSecrets

GitHubリポジトリの **Settings → Environments** で `staging` と `production` を作る。`production` にはRequired reviewersを設定する。

両Environmentへ、その環境専用の値を登録する。

| Secret | 内容 |
|---|---|
| `SSH_HOST` | XserverのSSHホスト名 |
| `SSH_PORT` | Xserverで指定されたSSHポート |
| `SSH_USER` | SSHユーザー名 |
| `DEPLOY_ROOT` | 例: `/home/<server-user>/valzeria.com` |

`DEPLOY_ROOT` は公開フォルダではなく `valzeria.com` 自体を指定する。ワークフローは対象に応じて `public_html` または `public_html/staging.valzeria.com`、`valzeria_*` または `staging_valzeria_*` だけを扱う。

XserverのCLI PHPは古い `php`（PHP 5.4）を指すため、ワークフローでは確認済みの `/usr/bin/php8.4` を固定で使用する。これは秘密情報ではないためGitHub Secretには登録しない。

## 実行方法

- ステージング: セルフホストRunnerを起動し、Actionsの **Deploy staging** を開いてmigration modeを選んで実行する。反映元は `main` 固定。
- 本番: Actionsの **Deploy production** を開き、確認欄へ `deploy-production` と入力する。GitHub Environmentの承認後、`main` のみを反映する。

通常のコード変更は、ステージングで実プレイ確認した後に本番ワークフローを明示実行する。Seeder・DB全消去・既存プレイヤー向けのデータ補正は、このワークフローに含めない。

ステージングを空DBへ戻す必要があるときだけ、Actionsの **Reset staging database** を手動実行し、確認欄へ `reset-staging-database` と入力する。このワークフローは `staging_valzeria_current` だけを対象に、現在のステージング用ゲームマスタをバックアップしてから `db:wipe` → `migrate` → 本番ゲームマスタ同期 → `db:seed` → `dungeon:validate` を実行する。同期後にSeederを実行するため、本番に未反映の追加コンテンツ用マスタもステージングで検証できる。同期ではステージングが持つ共通列だけをコピーし、件数一致を検証するため、本番だけに残る旧列で復元が失敗しない。ユーザー、キャラクター、所持品、ログ、決済などのプレイヤー/運用データは同期しない。本番DBは読み取り専用で、更新しない。

## 初回切替とロールバック

最初のSSHデプロイ前に、公開フォルダに通常ファイルとして残っている `images` / `build` / `storage` を確認する。リリーススクリプトは既存の画像・ビルド資産を削除せず、必要なファイルを上書き追加する。`storage` が通常ディレクトリの場合は自動で置き換えないため、既存運用を確認してから共有 `storage/app/public` へのリンクへ移行する。

コード切替後の失敗時は、`*_current` を直前リリースへ戻せる。実行済みmigrationは自動で戻さないため、非互換migrationやデータ変更は既存のバックアップ・承認手順を使う。

## 旧方式の扱い

`server_deploy_api.php` と `local_deploy*.php` は、SSH経路でステージングの初回リリースとスモークテストが成功済みでも、本番の初回リリースとスモークテストが成功するまで削除しない。成功後に秘密鍵・許可IP・公開APIを撤去する計画を別途実施する。
