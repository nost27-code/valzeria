# PWA化（Progressive Web App）対応 実装計画

Webアプリケーションをスマホ等のホーム画面に追加し、アプリのように動作させるための「PWA（Progressive Web App）化」を行います。

## 概要

PWAの要件を満たすため、以下の設定を追加します。
1. **Web App Manifest (`manifest.json`) の作成**: アプリ名、アイコン、表示モード（フルスクリーン/スタンドアロン）などを定義します。
2. **Service Worker (`sw.js`) の作成**: バックグラウンドでのリクエスト処理や、オフライン時の最低限のフォールバックなどを制御します。
3. **HTMLテンプレートへの組み込み**: 各画面のベースとなるレイアウトファイルに、マニフェストとService Workerの登録スクリプトを埋め込みます。

## User Review Required

> [!WARNING]
> PWAの Service Worker（キャッシュ機能）は強力なため、設定を誤ると**「ユーザーの環境で古いゲームデータや画面がずっと表示され続けてしまう（更新されない）」**という深刻なデグレを引き起こすリスクがあります。
> そのため今回は、**「キャッシュは行わず、常に最新のネットワークデータを取得する（Network Only）」**という最も安全な設定で Service Worker を実装します。これはPWAの「インストール可能要件」を満たしつつ、ゲームの不具合を防ぐためのベストプラクティスとなります。この方針で問題ないかご確認ください。

## Open Questions

> [!IMPORTANT]
> - **アプリ名（略称）の指定**: ホーム画面に追加した際にアイコンの下に表示される短い名前（12文字程度推奨）が必要です。「ヴァルゼリア」でよろしいでしょうか？
> - **テーマカラー**: スマホのステータスバーなどの色を指定できます。現在はメインカラーに合わせる形（例: `#312e81` (インディゴ系の暗色) または `#ffffff`）を想定していますが、ご希望の色があれば教えてください。
> - **アプリアイコン**: 現在配置されている `public/images/favicon.png` をPWAのアイコンとして流用しますがよろしいでしょうか？（後からより高解像度の正方形画像に差し替えることも可能です）

## Proposed Changes

### PWA コアファイル
#### [NEW] [manifest.json](file:///c:/Users/yuta/tool/tool/ffa/public/manifest.json)
- アプリ名、アイコン（`favicon.png`を利用）、`start_url` (`/`)、`display` (`standalone`)、テーマカラーを定義します。

#### [NEW] [sw.js](file:///c:/Users/yuta/tool/tool/ffa/public/sw.js)
- インストール要件を満たすためのService Worker。
- `fetch` イベントをリッスンしますが、データはキャッシュせず、常にネットワークから取得（Network Only）するように設定します。

---

### レイアウトファイルへの組み込み
以下のレイアウトファイルの `<head>` 内に、`<link rel="manifest" href="/manifest.json">` などのPWA用タグと、Service Workerを登録する `<script>` を追加します。

#### [MODIFY] [app.blade.php](file:///c:/Users/yuta/tool/tool/ffa/resources/views/components/layouts/app.blade.php)
#### [MODIFY] [facility.blade.php](file:///c:/Users/yuta/tool/tool/ffa/resources/views/components/layouts/facility.blade.php)
#### [MODIFY] [simple.blade.php](file:///c:/Users/yuta/tool/tool/ffa/resources/views/components/layouts/simple.blade.php)
#### [MODIFY] [welcome.blade.php](file:///c:/Users/yuta/tool/tool/ffa/resources/views/welcome.blade.php)

## Verification Plan

### Manual Verification
1. ローカルサーバーを起動し、Chromeの「DevTools > Application > Manifest / Service Workers」にて、エラーなくPWAとして認識されているか確認。
2. デプロイ後、Android/iOS端末、またはPCのChromeからアクセスし、「ホーム画面に追加（インストール）」のプロンプト・アイコンが表示されるか確認。
3. インストール後、スタンドアロン（ブラウザのURLバーがないアプリのような見た目）で起動するか確認。
