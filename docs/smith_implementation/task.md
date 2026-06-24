# 鍛冶屋（合成機能）実装タスク

- [x] 1. 生成した鍛冶屋の画像を `public/images/facilities/` にコピーし、それぞれ `blacksmith_symbol.png` と `blacksmith.png` にリネームする
- [x] 2. `app/Livewire/MainScreen.php` を編集し、街タブ（`town`）の宿屋の次に「鍛冶屋」を追加する
- [x] 3. `app/Livewire/MainScreen.php` の武具屋タブ（`shop`）にある古い「鍛冶屋（準備中）」の項目を削除する
- [x] 4. `routes/web.php` に鍛冶屋用のルート（`smith.index`、`smith.craft`）を追加する
- [x] 5. `php artisan make:controller SmithController` でコントローラーを作成し、現在地の街と紐づくレシピ取得処理を実装する
- [x] 6. 鍛冶屋の合成実行ロジック（素材消費とアイテム付与のトランザクション処理）を実装する
- [x] 7. `resources/views/smith/index.blade.php` を作成し、UIをゲームの世界観に合わせて構築する（スライダーや確認モーダル含む）
- [x] 8. ブラウザで動作確認・UI確認を行う
- [x] 9. `walkthrough.md` を作成して実装結果と教訓をまとめる
