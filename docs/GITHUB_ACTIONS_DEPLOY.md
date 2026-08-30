# GitHub Actions + SSH デプロイ

この方式では、公開PHPのデプロイAPIやFile Manager経由のZIPアップロードを使わない。GitHubホステッドRunnerがリリースをビルドし、このPCのリポジトリ専用セルフホストRunnerが成果物を受け取ってXserverへSSH/SCP転送する。SSH上のリリーススクリプトがmigration・キャッシュ生成・`current` 切替を行う。

## 安全設計

- ビルドはGitHubホステッドRunner、SSH転送だけをこのリポジトリ専用のWindowsセルフホストRunnerで行う。
- ステージング・本番は任意refを実行せず、workflow dispatch時点の信頼済み `main` SHAを固定してビルド・転送する。dispatch後に`main`が進んでも成果物の実体は変えない。
- `staging` と `production` はGitHub Environmentsを分け、同名でも別の接続先Secretsを登録する。
- 本番ワークフローは手動実行のみで、`deploy-production` の確認入力とGitHub Environmentの承認を両方必要にする。
- SSH秘密鍵と検証済み `known_hosts` はこのPCの実行ユーザー配下だけに置き、GitHub Secretsへ保存しない。
- Actionsは対象環境のローカル秘密鍵だけを使う非対話SSH接続を先に検証してから、アップロードやDB操作へ進む。
- ステージングと本番でSSH鍵を分ける。同一XserverのSSHユーザーを使う限りサーバー上のフォルダ権限は分離されないため、本番の実行制御はGitHub Environmentの承認と本番専用ワークフローで行う。
- ビルド時に実checkout SHAを `.release-sha` へ記録し、dispatch SHAとの一致を確認する。リモートはアーカイブ内のmarkerと転送時の期待SHAが一致しない限りmigrationや `*_current` 切替へ進まない。
- アーカイブはWeb公開領域外の `deploy-incoming` に置き、展開・SHA照合・migrationが成功してから `*_current` を原子的に切り替える。
- Viteのハッシュ付きCSS・JSは環境別の共有 `public-build` へ先に追加し、新旧資産を同時に配信できる状態にしてから `*_current` を切り替える。公開直後に新HTMLだけが先行してCSSが404になる状態を防ぐ。
- Bladeのコンパイル済みファイルは各リリースの `bootstrap/cache/views` に分離する。共有storageに置かないため、切替後に旧テンプレートが残らない。

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

- ステージング: セルフホストRunnerを起動し、Actionsの **Deploy staging** を `main` から開いてmigration modeを選んで実行する。runに記録されたSHAが固定の反映元になる。
- 本番: Actionsの **Deploy production** を `main` から開き、確認欄へ `deploy-production` と入力する。GitHub Environmentの承認後、runに記録された同一SHAだけを反映する。

通常のコード変更は、ステージングで実プレイ確認した後に本番ワークフローを明示実行する。Seeder・DB全消去・既存プレイヤー向けのデータ補正は、このワークフローに含めない。

既存プレイヤー向けの一括補正を専用workflowで行う場合は、対象runを `main` から実行し、デプロイ済みの40文字SHAを明示入力する。workflow自身のSHA・入力SHA・対象環境のactive `.release-sha` の3つが一致しない限り処理を中止する。称号の一括付与はschema監査、dry-run、apply、付与後dry-runの順で行い、apply中はmaintenance modeにして解除失敗もworkflow失敗として扱う。

### 緊急ホットフィックス

本番の 500 エラー等で主要画面または基本ゲームループが操作不能な場合は、ユーザーが当該タスク内で本番デプロイを明示承認した後、通常のステージング実プレイ確認を待たずに **Deploy production** を実行して復旧を優先してよい。

- 対象は障害原因だけを直す最小コミットに限定し、`main` へ push 済みであることを確認する。
- 実行前に変更ファイルの構文チェック、最も近い自動テスト、対象差分の確認を行う。
- migration mode は `none` とし、migration、Seeder、既存データ更新・削除、決済・通貨・認証変更を伴う修正は通常手順へ戻す。
- 本番反映直後に公開ページと障害画面を確認し、復旧後にステージング確認・追加の実プレイ確認を補完する。
- デプロイ報告には、緊急性、対象コミット、最小確認の結果、ロールバック先を記録する。

ステージングを空DBへ戻す必要があるときだけ、Actionsの **Reset staging database** を手動実行し、確認欄へ `reset-staging-database` と入力する。このワークフローは `staging_valzeria_current` だけを対象に、現在のステージング用ゲームマスタをバックアップしてから `db:wipe` → `migrate` → 本番ゲームマスタ同期 → `db:seed` → `dungeon:validate` を実行する。同期後にSeederを実行するため、本番に未反映の追加コンテンツ用マスタもステージングで検証できる。同期ではステージングが持つ共通列だけをコピーし、件数一致を検証するため、本番だけに残る旧列で復元が失敗しない。ユーザー、キャラクター、所持品、ログ、決済などのプレイヤー/運用データは同期しない。本番DBは読み取り専用で、更新しない。

## 初回切替とロールバック

最初のSSHデプロイ前に、公開フォルダに通常ファイルとして残っている `images` / `build` / `storage` を確認する。リリーススクリプトは既存の画像・ビルド資産を削除せず、必要なファイルを上書き追加する。`storage` が通常ディレクトリの場合は自動で置き換えないため、既存運用を確認してから共有 `storage/app/public` へのリンクへ移行する。

コード切替後の失敗時は、`*_current` を直前リリースへ戻せる。実行済みmigrationは自動で戻さないため、非互換migrationやデータ変更は既存のバックアップ・承認手順を使う。

## 旧方式の扱い

`server_deploy_api.php` と `local_deploy*.php` は、SSH経路でステージングの初回リリースとスモークテストが成功済みでも、本番の初回リリースとスモークテストが成功するまで削除しない。成功後に秘密鍵・許可IP・公開APIを撤去する計画を別途実施する。

旧ZIP経路で`backward_compatible`または`maintenance_required`を指定する場合、対象コミット側のクライアントは先にHTTPSの`X-Valzeria-Deploy-Contract`を確認し、version 2未満または確認不能なら送信を中止する。これにより、新しいpreflightを持つ`server_deploy_api.php`が公開側へ更新される前の1回だけmigrationが無検査で進むことを防ぐ。

- 推奨: GitHub Actionsから`remote-release.sh`を使う。
- 旧ZIP経路を更新する必要がある場合: `DEPLOY_MIGRATION_MODE=none`でAPIを先行更新し、契約version 2を確認できてから必要なmigration modeで再実行する。
- `none`を含む旧APIの全deployで、対象releaseのmaster検証とrelease readinessは公開切替前に必須とする。緊急修正でもこの検査は迂回せず、検査自体の障害は別の最小hotfixとして扱う。
