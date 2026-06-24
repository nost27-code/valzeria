# ヘッダーとチャットの全幅化＆左カラム改修の実装計画

ユーザーからの要望に基づき、ヘッダーとチャット欄を「全幅」にし、左カラムのメニュー部分を削除してステータス枠を伸ばすためのレイアウト改修を行います。

## 変更の目的
現在の「ヘッダー」と「チャット」は、ホーム画面（`main-screen`）の右カラム内に組み込まれているため、全幅表示になっていません。これを画面全体の共通レイアウトである `app.blade.php` の管轄に移動し、全画面で共通かつ全幅で表示されるようにします。
また、左カラムにあったメニューを削除し、ステータス表示のスペースを確保します。

## 提案するUIアーキテクチャ
```blade
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex flex-col gap-4">
    <!-- 1. 全幅ヘッダー -->
    <livewire:city-header />

    <!-- 2. 中段：左サイドバーとメインコンテンツ -->
    <div class="flex flex-col md:flex-row gap-6 flex-grow">
       <!-- 左サイドバー（ステータス・装備のみ） -->
       <div class="w-full md:w-1/4 lg:w-1/5 shrink-0">
           <livewire:left-sidebar />
       </div>
       
       <!-- メインコンテンツ（施設ハブや各画面など） -->
       <div class="w-full md:w-3/4 lg:w-4/5">
           {{ $slot }}
       </div>
    </div>

    <!-- 3. 全幅チャット -->
    <livewire:chat-log />
</main>
```

## User Review Required

> [!IMPORTANT]
> - 現在、移動メニュー（宿場町、武具屋など）はホーム画面（`main-screen`）の上部タブとして実装されています。左カラムのメニューを削除すると、**他の画面（例：転職所や武具屋の中）にいるときに、直接別の施設へ移動するリンクがなくなります**（一度「戻る」ボタンや上部ナビゲーションなどでホームに戻る必要があります）。この仕様で問題ないか、あるいは全画面共通のナビゲーション（グローバルナビ等）を別途設ける必要があるか、ご意見をお聞かせください。

## Proposed Changes

### 1. 新規Livewireコンポーネントの作成
- **`app/Livewire/CityHeader.php` / `resources/views/livewire/city-header.blade.php`** [NEW]
  - `MainScreen` にあった「現在の冒険者数」「決闘数」「王者」「ログイン中プレイヤー」の取得処理とビューをここに切り出します。
- **`app/Livewire/ChatLog.php` / `resources/views/livewire/chat-log.blade.php`** [NEW]
  - `MainScreen` にあったチャットログと発言フォームの処理とビューをここに切り出します。

### 2. 共通レイアウトの修正
- **`resources/views/components/layouts/app.blade.php`** [MODIFY]
  - 上部に `<livewire:city-header />`、下部に `<livewire:chat-log />` を配置し、フレックスレイアウトで全幅になるように整えます。

### 3. 左カラムの修正
- **`resources/views/livewire/left-sidebar.blade.php`** [MODIFY]
  - 下部にある移動メニューの `<ul>` リスト部分を削除し、ステータス枠を下まで伸ばすデザインに変更します。

### 4. 既存メイン画面の整理
- **`app/Livewire/MainScreen.php` / `resources/views/livewire/main-screen.blade.php`** [MODIFY]
  - ヘッダーとチャットの取得・表示コードを削除し、「施設の表示（中段右カラム分）」のみを担当するように整理します。

## Verification Plan

### Manual Verification
1. `http://127.0.0.1:8000/home` にアクセスし、ヘッダーとチャットが期待通り全幅で表示されることを確認する。
2. 左カラムからメニューが消え、ステータスと装備の表示枠がスッキリと伸びていることを確認する。
3. 他の画面（例：転職所 `http://127.0.0.1:8000/jobs`）に遷移しても、全幅ヘッダーと全幅チャットが正常に表示されるか確認する。
