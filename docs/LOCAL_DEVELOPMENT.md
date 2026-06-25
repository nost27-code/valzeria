# Local Development

ローカル環境は、公開中のβ版へ影響を出さずに画面・導線・基本ゲームループを確認するための検証環境です。

## 初回セットアップ

PowerShellでリポジトリ直下から実行します。

```powershell
powershell -ExecutionPolicy Bypass -File scripts/local-setup.ps1 -ResetEnv
```

`-ResetEnv` を付けると、既存の `.env` を `.env.backup.local-YYYYMMDD-HHMMSS` に退避し、`.env.local.example` から安全なローカル用 `.env` を作ります。

## 起動

```powershell
powershell -ExecutionPolicy Bypass -File scripts/local-dev.ps1
```

Laravel と Vite はバックグラウンドで起動し、ログは `storage/logs/local-serve.*.log` と `storage/logs/local-vite.*.log` に出力されます。

ブラウザで以下を開きます。

```text
http://127.0.0.1:8000
```

Viteを使わず、ビルド済みCSS/JSだけで確認する場合:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/local-dev.ps1 -NoVite
```

停止する場合は、起動時に表示されたPIDを指定します。

```powershell
Stop-Process -Id <PID>
```

## ローカル環境の方針

- DBは `database/database.sqlite` を使います。
- メール送信は `log` にします。
- POP3メール受信設定は空にします。
- Stripeキーは空にします。
- ポチゲーポータル送信は `POCHI_GAME_PORTAL_ENABLED=false` にします。

## よく使う確認コマンド

```powershell
C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64\php.exe artisan view:cache
npm run build
```

`php artisan route:list` は、既存の未解決Controllerがある場合に失敗することがあります。その場合は対象ルートだけ別手段で確認してください。

## 注意

本番用の外部サービス設定をローカル `.env` に入れたまま起動しないでください。メール、決済、外部API送信が本番へ向く可能性があります。
