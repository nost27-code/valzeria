# ヴァルモン地下探検 試験実装

このフォルダは `docs/` 配下のため、現行の `local_deploy.php` のフルデプロイZIPには含まれません。

## 開き方

`index.html` をブラウザで開きます。

```text
C:\Users\yuta\tool\tool\ffa\docs\experiments\valmon-deep-crawl\index.html
```

## 実装している範囲

- 金曜09:00から月曜09:00までの週末カードシーズン
- シーズンごとのカード、TP、進行階層、デッキ状態リセット
- 100枚カードプール、今週出現80枚、休眠20枚
- 金貨の時間解放、受取、カード3択取得。開始時2枚、以後4時間ごと2枚、月曜05:00解放分で最大36枚
- 1〜10階の初回突破カード報酬
- 地下へ降りた時のTP獲得。階層ごとに1シーズン1回だけ付与
- 金貨によるカード3択、シーズンTP+1
- カード3択専用画面での横並び選択とカード説明表示
- TPによるシーズンTP+1、HP回復、装備枠拡張、カード取得
- 通常TP消費は1回目1TP、2回目2TP、N回目N TP
- 装備枠拡張TPは1回目1TP、2回目3TP、N回目2N-1 TP
- 所持カードからのデッキ編集
- 初期デッキ枠20枚、最大36枚
- 探索力1消費で現在階層へ進行
- 敗北時は同じ階層から再挑戦
- HP持ち越し、敗北時HP全回復
- 戦闘、罠、宝箱、小休止、移動探索、黒風の気配、階層主
- 最高踏破階、スコア、挑戦回数、達成日時によるランキング

## 本番未接続

- 実DBは使わず `localStorage` に保存します。
- 実ヴァルモン所持、実探索力、実ランキングには接続していません。
- Laravel route、migration、model、service、Blade は追加していません。
- 金貨の課金先取りはUI未実装です。

## 本番移設時の主な置き換え

- `config.js` -> `config/valmon_tower.php` と Seeder
- `app.js` のシーズン生成 -> `ValmonTowerSeasonService`
- `app.js` の金貨計算 -> `ValmonTowerCoinService`
- `app.js` のカード3択 -> `ValmonTowerCardChoiceService`
- `app.js` のデッキ編集 -> `ValmonTowerDeckService`
- `app.js` のTP処理 -> `ValmonTowerTpService`
- `app.js` の階層進行 -> `ValmonTowerProgressService`
- `app.js` のランキング更新 -> `ValmonTowerRankingService`
- `index.html` -> Blade/Livewire または Controller + Blade
