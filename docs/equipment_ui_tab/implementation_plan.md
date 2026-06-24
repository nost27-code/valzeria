# 装備変更画面のタブ切り替え実装計画

所持アイテムが増加した際に画面が縦に長くなりすぎる問題を解消するため、装備変更画面（`resources/views/equipment/index.blade.php`）をタブ切り替え式に改修します。

## Proposed Changes

### 1. タブ切り替えUIの実装 (Alpine.jsの活用)
#### [MODIFY] [resources/views/equipment/index.blade.php](file:///c:/Users/yuta/tool/tool/ffa/resources/views/equipment/index.blade.php)
- 装備変更エリアのコンテナに Alpine.js の `x-data="{ activeTab: 'weapon' }"` を設定し、状態管理を行います。
- 画面上部に「武器」「防具」「装飾品」の3つのタブボタンを配置します。
- 各カテゴリのリスト部分（`<div class="space-y-3">`）を `x-show` ディレクティブでラップし、選択されたタブのアイテム群のみが表示されるように制御します。

### 2. UIフィードバック（ボタンを押した感）の追加
タブボタンに対して、視覚的なフィードバックを実装します。
- **アクティブ状態の明示**: 選択中のタブは背景色や文字色を変え、一目でわかるようにします（例: `bg-blue-600 text-white`）。
- **非アクティブ状態**: `bg-gray-100 text-gray-600 hover:bg-gray-200` のようにし、ホバー時に反応させます。
- **クリックアニメーション**: `transition-transform duration-150 active:scale-95` を付与し、クリックした瞬間に少しへこむ（沈み込む）ような「押した感」を演出します。

## User Review Required

> [!NOTE]
> タブ切り替えはAlpine.jsを用いて画面遷移なし（即時切り替え）で行う想定です。
> また、タブのデザインは既存のUI（青系・丸みのあるボタンなど）に馴染むように構築しますが、特に指定したいテーマカラーなどがあればお知らせください。

## Verification Plan

### Manual Verification
1. ブラウザでローカルサーバーにアクセスし、装備変更画面を開く。
2. デフォルトで「武器」タブのみが表示されていることを確認する。
3. 「防具」「装飾品」タブをクリックした際、リストが瞬時に切り替わることを確認する。
4. クリックした際、タブボタンに「押した感（少し沈むアニメーション）」があるか確認する。
