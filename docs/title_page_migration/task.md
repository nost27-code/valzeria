# 称号ページ移行タスクリスト

- [x] 1. `routes/web.php` に `/titles` (称号一覧ページ) のルートを追加
- [x] 2. `TitleList` へのLivewireコンポーネントの移行 (`TitleListModal.php` をコピーして変更)
- [x] 3. `title-list.blade.php` の作成（タップで装備可能なUI、メインレイアウトへの組み込み）
- [x] 4. `LeftSidebar` から `/titles` へのリンク修正とモーダル呼び出しの削除
- [x] 5. 不要になった `TitleListModal.php` および `title-list-modal.blade.php` の削除
- [x] 6. 動作確認
