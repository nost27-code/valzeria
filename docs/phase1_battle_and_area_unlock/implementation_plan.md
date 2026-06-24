# 冒険都市ヴァルゼリア フェーズ1 実装計画

ご提示いただいた仕様書 `valzeria_phase1_battle_and_area_unlock_spec.md` に基づき、フェーズ1の「迷宮探索・戦闘・エリア解放」の機能実装を行うための計画です。

## 目的
冒険都市ヴァルゼリアを「見た目だけの画面」から、実際に「迷宮を探索し、敵と戦い、経験値とGOLDを得て成長し、ボスを倒して次のエリアへ進む」という**基本ゲームループが遊べる状態**へ引き上げます。

## User Review Required

> [!IMPORTANT]
> 以下の点について、実装を進める前にご確認・ご了承をお願いいたします。
> 
> 1. **データベースのマイグレーションについて**
>    現在稼働中の `characters` テーブルには「現在HP(`current_hp`)」や「称号(`title`)」などのカラムが不足しているため、新しいマイグレーションファイルを作成してカラムを追加します。また、`areas`, `enemies`, `battle_logs`, `public_logs` などのテーブルを新規作成します。既存データ（テスト用のユーザーなど）は保持する形で進めます。
> 2. **通信と画面遷移について（ご要望反映済）**
>    「探索する」や「ボスに挑む」ボタンを押した際は、Livewireの非同期通信ではなく、**専用の戦闘結果画面へページ遷移する**昔ながらのCGIゲームスタイルで実装いたします。

## 提案する実装内容（Proposed Changes）

### 1. データベース（Migrations & Models）
以下のテーブル作成と、既存テーブルへのカラム追加を行います。

* **[MODIFY] `characters` テーブル**
  * 追加: `current_hp`, `title`, `pvp_rank`, `reincarnation_count`
  * ※既存の `attack_base` 等はそのまま仕様書にある `STR` 等の基礎値として流用します。
* **[NEW] `areas` テーブル**（エリアマスター）
* **[NEW] `character_area_progresses` テーブル**（キャラごとの解放状態）
* **[NEW] `enemies` テーブル**（敵マスター）
* **[NEW] `battle_logs` テーブル**（個人戦闘ログ）
* **[NEW] `public_logs` テーブル**（公開ログ）

各モデルファイル（Area, Enemy, BattleLog, PublicLog など）も作成します。

### 2. サービス層（Services）
仕様書に基づき、ビジネスロジックをカプセル化する以下の Service クラスを作成します。

* **[NEW] `app/Services/AreaService.php`**（エリア一覧、進行度管理）
* **[NEW] `app/Services/ExplorationService.php`**（探索条件チェック、敵抽選、クールタイム判定）
* **[NEW] `app/Services/BattleService.php`**（自動戦闘、ダメージ計算、勝敗判定）
* **[NEW] `app/Services/LevelService.php`**（EXP加算、レベルアップ判定・ステータス上昇）
* **[NEW] `app/Services/PublicLogService.php`**（街の公開ログ生成）
* **[NEW] `app/Services/BattleLogService.php`**（個人戦闘ログ保存）
* **[NEW] `app/Services/InnService.php`**（宿屋でのHP全回復）

### 3. コントローラー / UI層
* **[NEW] `app/Http/Controllers/BattleController.php`**
  * 仕様書のルーティング案に基づき、探索 (`explore`) やボス戦 (`boss`) のリクエストを受け取り、専用の「戦闘結果画面」へ遷移させるコントローラーを作成します。
* **[NEW] `resources/views/battle/result.blade.php`**
  * 画面遷移先となる戦闘結果専用のBladeテンプレートです。戦闘ログ、結果、獲得報酬、レベルアップ情報、および「もう一度探索する」「迷宮へ戻る」ボタンを表示します。
* **[MODIFY] `app/Livewire/MainScreen.php`**
  * 現在のダミーデータを外し、`AreaService` を通じて**DBから取得したエリア情報・解放状態**を表示するように変更します。
  * 「探索する」「ボスに挑む」などのアクションはLivewireの非同期処理ではなく、`<form>` タグや通常のリンクを用いて `BattleController` へ遷移するように変更します。
* **[MODIFY] `resources/views/livewire/main-screen.blade.php`**
  * エリアカードのボタンをフォーム送信に変更します。
  * 下部のログ枠に `PublicLogService` から取得した最新の公開ログを表示します。

### 4. 初期データ投入（Seeder）
* **[NEW] `database/seeders/Phase1Seeder.php`**
  * はじまりの草原、小鬼の森、古びた洞窟のエリアデータ
  * スライム、ゴブリンなどの通常敵とボスデータ
  * 初期登録用の公開ログ

## 進行順序（優先度）
仕様書の「23. 実装優先順位」に従い、以下のステップで進めます。
1. DB・マスターデータ・初期データ（Seeder）の実装
2. Livewire と Service 層の連携（探索処理の骨組み）
3. 戦闘・勝敗・レベルアップ・エリア解放の実装
4. ログ保存・表示と宿屋回復・クールタイムの実装

---
問題なければ、この計画通りにフェーズ1の実装を開始します。ご意見や修正点がございましたらお知らせください。
