# 敵マスター・ボス戦・エリア解放 実装計画

本計画は `valzeria_phase1_enemy_master_boss_area_unlock_spec.md` に基づき、現在一部実装済み（DBスキーマ、初期データ投入、探索処理等）のシステムに対して、不足している要件を補完・実装するものです。

## 現状の分析
すでに以下の機能は実装済みです。
- `enemies`, `character_area_progresses` テーブル、AreaSeeder/EnemySeeder。
- `ExplorationService` における敵の重み付け抽選（`appearance_weight`）と、ボス敵かどうかの分岐。
- `AreaService` におけるボス討伐時の次エリア解放処理（`is_unlocked`, `boss_defeated` の更新）と、`PublicLog`（公開ログ）への反映。
- `MainScreen` における `dungeon` タブ時のエリア一覧表示と状態（ロック/解放済み等）の反映。

## 不足している機能と今回の改修対象

### 1. 「次の目標」の動的生成
現在 `MainScreen.php` で固定値（`'エリアのボスを倒して、次の迷宮を解放しよう'`）となっている左カラムの目標文言を、プレイヤーの進行状況に応じて動的に生成する `CharacterGoalService` を作成します。

### 2. 迷宮カード（エリア一覧）のUI微調整
ボスを撃破済みのエリアでは、「ボスに挑む」ボタンを非表示にする（または押せなくする）よう、`MainScreen.php` と `main-screen.blade.php` を調整します。

### 3. 戦闘結果画面へのボス撃破・エリア解放表示
`resources/views/battle/result.blade.php` において、ボス戦で勝利した場合に「〇〇を倒しました。次のエリアが解放されました！」というメッセージを表示し、次のエリアへの導線を設置します。
そのために `BattleController` からViewへ `$isBoss` や解放されたエリア情報などを渡すようにします。

## ユーザーレビュー事項

> [!IMPORTANT]
> 既存の `AreaService` や `ExplorationService` では大枠の処理が既に書かれていたため、本タスクでは **「左カラムの次の目標の動的化」「迷宮画面でのボス撃破済みエリアからのボス挑戦ボタン除去」「ボス戦勝利時のリザルト画面の強化」** の3点がメインの改修となります。
> こちらの方針で残りの実装を進めてよろしいでしょうか？

## オープンな質問
特になし

## 提案される変更

### 1. 新規サービス
#### [NEW] `app/Services/CharacterGoalService.php`
- キャラクターの `CharacterAreaProgress` を確認し、未撃破のボスがいる最も古い解放済みエリア、あるいは全クリア等の状態を判断し、適切な「次の目標」テキストを返す。

### 2. 既存ファイルの改修
#### [MODIFY] `app/Livewire/MainScreen.php`
- `render()` メソッドで `CharacterGoalService` を呼び出し、`$nextGoal` に動的テキストをセットする。
- `dungeon` 生成処理で、`boss_defeated` の場合は `boss_action` をセットしないように修正。

#### [MODIFY] `resources/views/livewire/main-screen.blade.php`
- 施設カード表示時に `$facility['boss_action']` がセットされていない場合はボス戦ボタンを表示しないように微調整（現状のBladeでも `isset($facility['boss_action'])` で分岐されているので、MainScreen.php 側の修正で動くはずです）。

#### [MODIFY] `app/Http/Controllers/BattleController.php`
- `boss()` メソッドからの呼び出し時、リザルトへ `$isBoss = true` と、解放されたエリアの情報（あれば）を渡す。

#### [MODIFY] `resources/views/battle/result.blade.php`
- ボス戦勝利時（`$isBoss && $result['result'] === 'win'`）の場合の特別メッセージと、次エリアへのリンクを追加。

## 検証計画
- ブラウザ上でメイン画面を開き、「次の目標」が初期状態の「はじまりの草原のボスを...」になっているか確認。
- 「はじまりの草原」の「ボスに挑む」ボタンを押し、勝利した場合にリザルト画面に「小鬼の森が解放されました」と表示されるか確認。
- メイン画面に戻り、「はじまりの草原」の「ボスに挑む」ボタンが消えていること、「小鬼の森」が解放されていること、「次の目標」が「小鬼の森のボスを...」に変わっていることを確認。
