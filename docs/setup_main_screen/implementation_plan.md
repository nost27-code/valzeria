# 実装計画: メイン画面初期セットアップ

## 概要
FFA（冒険者の街）のモダンナイズ版として、Laravel 11, Livewire 3, Tailwind CSSを使用した基盤を構築し、初期のメイン画面（2カラムUI）とキャラクターテーブルのマイグレーションを実装する。

## 目標
1. Laravel 11プロジェクトの新規作成
2. Tailwind CSSおよびLivewire 3のセットアップ
3. キャラクター情報を管理する `characters` テーブルの作成
4. Livewireを用いた非同期メイン画面（`MainScreen`）の実装

## 実装手順
1. `composer create-project laravel/laravel .` を用いて現在のディレクトリにLaravelをインストールする。
2. Livewire 3 と Tailwind CSS をインストールし、設定を行う。
3. `create_characters_table` マイグレーションを作成し、提案済みのインデックスやカラム定義を反映する。
4. `php artisan make:livewire MainScreen` でコンポーネントを作成し、Bladeビューとクラスの実装を反映する。
5. `routes/web.php` のルートパスを `MainScreen` に向ける。
6. Viteビルドを実行し、表示確認を行う。
