# フェーズ1 武具屋・装備 実装タスク

- [x] `Item`, `CharacterItem` モデルとマイグレーションの作成
- [x] `ItemSeeder` の作成・登録、およびマイグレーション実行
- [x] `GrantInitialEquipment` コマンドの作成と実行（既存キャラへの初期装備付与）
- [x] `CharacterStatusService` の作成
- [x] `ShopService`, `EquipmentService` の作成
- [x] `BattleService` の改修（`CharacterStatusService`を利用するよう変更）
- [x] `ShopController`, `EquipmentController` とルーティングの追加
- [x] ショップ画面 (`resources/views/shop/*`) のUI作成
- [x] 装備画面 (`resources/views/equipment/index.blade.php`) のUI作成
- [x] 左カラム (`MainScreen.php`) の装備・ステータス反映処理追加
- [x] 動作確認（購入、装備変更、戦闘への反映）
