# Google OAuth ログイン設定手順

本番または実際にGoogleログインを動かす際の手順です。

## 1. Google Cloud Console での準備
1. [Google Cloud Console](https://console.cloud.google.com/) にアクセスし、新しいプロジェクトを作成します。
2. 左側メニューの「APIとサービス」 > 「OAuth 同意画面」を開き、**外部** を選択して作成します。
   - アプリ名: 「FFA - 冒険者の街」など
   - サポートメール: ご自身のメールアドレス
   - デベロッパーの連絡先情報: ご自身のメールアドレス
   ※ 初期実装段階ではこれだけでOKです。
3. 「認証情報」タブに移動し、「認証情報を作成」 > **「OAuth クライアント ID」** を選択します。
4. アプリケーションの種類を **「ウェブ アプリケーション」** にします。
5. **「承認済みのリダイレクト URI」** に、以下のURLを追加します。
   - `http://localhost:8000/auth/google/callback` (ローカル開発環境用)
   - `https://あなたのドメイン/auth/google/callback` (本番サーバー用)
6. 作成ボタンを押すと、**「クライアント ID」** と **「クライアント シークレット」** が発行されるので控えます。

## 2. Laravelプロジェクト側の設定
1. プロジェクトルートの `.env` ファイルに、取得したキーを追記します。
```env
GOOGLE_CLIENT_ID="取得したクライアントID"
GOOGLE_CLIENT_SECRET="取得したクライアントシークレット"
GOOGLE_REDIRECT_URI="http://localhost:8000/auth/google/callback"
```

2. `config/services.php` に以下の設定を追加します（すでに追記済みの場合は不要です）。
```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

## 3. パッケージの導入
以下のコマンドで Laravel Socialite をインストールします。
```bash
composer require laravel/socialite
```

以上で設定は完了です。コントローラー（またはAuthService）で `Socialite::driver('google')->redirect()` を呼べばGoogleログイン画面へ遷移するようになります。
