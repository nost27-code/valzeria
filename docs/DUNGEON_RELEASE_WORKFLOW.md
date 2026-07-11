# ダンジョン追加・検証・公開手順

新規ダンジョンは、まず未公開で投入し、ステージングで確認してから明示的に公開する。既存のエリアID・敵ID・報酬値を変更する手順ではない。

## 追加前

1. エリア、敵、素材、発見リンクを追加する。外縁ダンジョン生成コマンドは新規エリアを `is_published=false` で保存する。
2. migration を適用する。
3. `php artisan dungeon:validate` を実行し、参照エラーがないことを確認する。

`dungeon:validate` は、都市、前提エリア、敵、素材のダンジョン参照、素材ドロップ、発見リンク（city / area / route_area）を検査する。未公開ダンジョンへの発見リンクは警告扱いで、公開前の到達・噂表示はアプリ側で抑止する。

## ステージング

1. `.env.staging.example` を基に、ステージング専用の `.env` をサーバー共有領域に作成する。本番のDB、Stripe、Google OAuth、ポータル送信設定は複製しない。
2. `VALZERIA_STAGING_DEPLOY_SECRET` はローカル実行環境にだけ設定する。値はリポジトリへ保存しない。
3. コミット済みの作業ツリーから `local_deploy_staging.php` を実行する。同スクリプトは `staging.valzeria.com` 以外へ送信せず、未コミット変更があれば停止する。
4. 空DBでは migration → `php artisan db:seed` → `php artisan dungeon:validate` の順に行う。
5. 新規キャラクターで、街表示、通常探索、戦闘報酬、ボス挑戦、発見リンク、未公開ダンジョンの非表示と直URL拒否を確認する。

## 公開

1. ステージングの通しプレイと `dungeon:validate` が通過したことを確認する。
2. 本番で `php artisan dungeon:publish <area_id> --confirm` を実行する。`--confirm` なしは対象一覧だけを表示し、状態を変更しない。
3. 公開後に該当の街・探索・ボス挑戦を確認する。

公開コマンドはエリア公開状態だけを変更する。migration、Seeder、プレイヤー進行、IDの変更は実行しない。
