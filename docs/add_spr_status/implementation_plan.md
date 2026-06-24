# SPR（精神力）ステータスの復活・追加実装計画

システムから抜け落ちていた `SPR（精神力・魔法防御力）` を復活させ、全レイヤー（DB、ロジック、UI）に統合します。

## オープンクエスチョン
*   **既存データの初期値について**：既に作成されているキャラクター（テストプレイ用など）や敵キャラの SPR は、一旦初期値（`10` など）で一律設定させてもよろしいでしょうか？

## Proposed Changes

### 1. データベース層 (Migrations & Models)
*   [NEW] `database/migrations/xxxx_xx_xx_add_spr_to_characters_table.php` (キャラの `spirit_base` 追加)
*   [NEW] `database/migrations/xxxx_xx_xx_add_spr_to_enemies_table.php` (敵の `spr` 追加)
*   [NEW] `database/migrations/xxxx_xx_xx_add_spr_to_items_table.php` (アイテムの `spr_bonus` 追加)
*   [MODIFY] `app/Models/Character.php` ($fillableに spirit_base が必要であれば追加)

### 2. ビジネスロジック層 (Services)
*   [MODIFY] `app/Services/JobService.php` (基礎値に job_classes の spr_rate を掛けて SPR を算出する処理を追加)
*   [MODIFY] `app/Services/CharacterStatusService.php` (装備品の spr_bonus を合算し、最終ステータスとして `spr` を返すように追加)
*   [MODIFY] `app/Services/BattleService.php` (戦闘計算などで敵・味方のSPRを読み込むように調整)

### 3. ビュー層 (UI)
*   [MODIFY] `resources/views/livewire/left-sidebar.blade.php` (ステータス一覧に SPR を追加し、レイアウトを調整)

## Verification Plan
*   **手動確認**: 
    1. ホーム画面の左カラムステータスに「SPR」が表示されていること。
    2. 装備を変更した際、SPR補正（もしあれば）が正しく計算されて画面に反映されること。
    3. 迷宮探索での戦闘でエラーが発生しないこと。
