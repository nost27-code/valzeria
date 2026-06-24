# スプライトシート生成ツール

キャラ、ヴァルモン、アイコン、街・ダンジョンシンボルなどの画像をまとめて、スプライトシート画像、JSON座標情報、CSSクラスを生成するためのArtisanコマンドです。

## コマンド

```bash
php artisan valzeria:make-sprite-sheet images/chara --name=chara --cell=128 --columns=8 --force
```

生成先は標準で `public/generated/sprites` です。

- `chara.png` / `chara.json` / `chara.css`
- `icon.png` / `icon.json` / `icon.css`
- `valmon.png` / `valmon.json` / `valmon.css`

## よく使う例

```bash
php artisan valzeria:make-sprite-sheet images/chara --name=chara --cell=128 --columns=8 --force
php artisan valzeria:make-sprite-sheet images/icon --name=icon --cell=48 --columns=8 --force
php artisan valzeria:make-sprite-sheet images/symbol --name=symbol --cell=64 --columns=10 --force
php artisan valzeria:make-sprite-sheet images/valmon --name=valmon --cell=96 --columns=8 --include=valmon*.webp,val_egg*.webp --force
```

## 主なオプション

- `--cell=64`: 正方形セルサイズ
- `--cell-width=64 --cell-height=80`: 長方形セルを使う
- `--columns=8`: 1行あたりの画像数
- `--padding=2`: セル間の余白
- `--recursive`: サブディレクトリも対象にする
- `--format=png`: `png` または `webp`
- `--extensions=png,webp`: 対象拡張子
- `--include=valmon*.webp`: 対象ファイル名パターン
- `--exclude=ranch_bg*.webp`: 除外ファイル名パターン
- `--force`: 既存ファイルを上書き

## CSS利用例

```html
<link rel="stylesheet" href="/generated/sprites/chara.css">
<span class="sprite-chara sprite-chara_1"></span>
```

JSONには各画像の `x`, `y`, `width`, `height`, 元画像サイズ、実描画サイズが入ります。Canvasや独自コンポーネントで使う場合はJSONを参照してください。

## 注意

このツールは生成のみを行います。既存画面の表示を自動でスプライト参照へ切り替えるものではありません。
背景画像のような大きい画像は、`--include` または `--exclude` で除外してください。
