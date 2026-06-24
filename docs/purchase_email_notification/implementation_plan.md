# Stripe決済完了時のメール通知機能の実装

Stripeの決済（Checkout）完了時に、購入者（ユーザー）と管理者（運営）の双方へ購入完了メールを送信する仕組みを作成します。

> [!WARNING]
> 現在のプロジェクト設定（`.env`）では、メール送信ドライバが `MAIL_MAILER=log` となっており、**実際のメールは送信されずログ（`storage/logs/laravel.log`）に書き込まれる状態**です。
> 実際にメールを送信するには、本番環境またはテスト環境の `.env` ファイルにて、GmailやSendGridなどのSMTPサーバー設定（`MAIL_MAILER=smtp`, `MAIL_HOST` 等）をご自身で追記していただく必要があります。
> 実装のテスト自体はログの出力で確認可能です。

## Open Questions

> [!IMPORTANT]
> **1. 管理者（運営）のメールアドレスについて**
> 新しく `.env` に `ADMIN_EMAIL=yuta.nostalgia@gmail.com` のような設定を追加し、そこへ通知を送る仕組みにします。この方針でよろしいでしょうか？
> 
> **2. 購入者のメールアドレスについて**
> 購入者のメールアドレスは、Stripeの決済画面で入力されたメールアドレス（`$session->customer_details->email`）を取得して送信します。決済時にメールアドレスが未入力の場合は購入者への送信をスキップします。

## Proposed Changes

### メール機能 (Mailables & Views)

#### [NEW] app/Mail/PurchaseCompletedNotification.php
* 購入者へ送るメールを組み立てるMailableクラス。
* 注文情報（輝石の数、金額）をビューに渡します。

#### [NEW] resources/views/emails/purchase_completed.blade.php
* 購入者向けメール本文のテンプレート。

#### [NEW] app/Mail/AdminPurchaseNotification.php
* 管理者へ送るメールを組み立てるMailableクラス。
* 誰が（キャラクター名やID）、何を購入したかの情報をビューに渡します。

#### [NEW] resources/views/emails/admin_purchase_notification.blade.php
* 管理者向けメール本文のテンプレート。

---

### コントローラーの修正 (Controller)

#### [MODIFY] app/Http/Controllers/StripeWebhookController.php
* `handleCheckoutSessionCompleted` メソッド内に、注文保存後の処理としてメール送信処理（`Mail::to()->send()`）を追加します。
* 購入者のメールアドレス（Stripeからのデータ）と、管理者のメールアドレス（`.env` または設定ファイル）宛にそれぞれのMailableを送信します。

## Verification Plan

### Automated Tests
* 今回は自動テストを追加せず、手動での確認をメインとします。

### Manual Verification
1. ローカルまたはテスト環境でStripeのテスト決済を実行します。
2. Webhookがトリガーされた後、`storage/logs/laravel.log` を確認し、購入者向け・管理者向けのメール本文がログとして正常に出力されていることを確認します。
3. 問題なければ、本番環境の `.env` にSMTP設定および `ADMIN_EMAIL` を追加していただくようご案内します。
