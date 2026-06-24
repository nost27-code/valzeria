# タスクリスト: 1アカウント1キャラクター制限の実装

- `[x]` CharacterSelect.php の mount() を修正 (1キャラ時は自動選択してホームへ, 0キャラ時は作成画面へ)
- `[x]` CharacterSelect.php 側のビューで「新規作成」ボタンの表示制御
- `[x]` CharacterCreate.php の mount() および create() を修正 (既にキャラがいる場合に作成拒否)
- `[x]` ローカル環境での動作検証 (新規作成、自動遷移、複数作成ガード)
- `[x]` 本番環境へのデプロイ (local_deploy.php) と動作確認
