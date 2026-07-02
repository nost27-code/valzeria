# AI_CONTEXT.md 修正案（**2026-07-02 適用済み**。ただし Recent implementation state の移設は未実施＝別タスク）

## 要裁定リスト（2026-07-02 すべて裁定済み）

1. **MP vs SP** → 裁定: プレイヤー向け表示・新規ドキュメントの正表記はSP。MP/STR/AGIは旧表記として修正対象。ただしDBカラム（mp系等）は当面リネームしない。valzeria_specのステータス仕様表の更新は別タスク（未反映）。
2. **転職条件** → 裁定: 正仕様は現実装どおり「Lv30以上+要求されている職のマスター」。valzeria_specの「Lv100」は未採用案として扱い、コードへ反映しない。Lv100化する場合は別途「転職条件再設計タスク」。

## 発見した問題（横断レビュー結果）

1. **致命的な誤記**: Product summary が「browser-based **pro-baseball GM** / RPG-style web game」— 別プロジェクトのテンプレ残骸。Codexがこれを読むと世界観を誤解する。
2. **Stack欄が丸ごとプレースホルダ**: `<Next.js / React>`、`<Supabase / PostgreSQL>` のまま。実際は Laravel 11 + Livewire v3 + Blade + Alpine.js + Tailwind + MySQL + Google OAuth + Xserver ZIPデプロイ。AGENTS.mdの「node_modules, .next, dist を除外」も Next.js 前提の残骸。
3. **Current feature map が全行 `?` 未確認**: 情報量ゼロのままトークンを消費している。
4. **「Recent implementation state」の肥大化**: 「Keep this section short」と書きながら45項目・1項目500字超もある。恒久ルールは DOMAIN_RULES.md と重複しており、二重更新の温床。
5. **Last updated / Branch / Commit がテンプレのまま**: 鮮度判定ができない。
6. 他ファイルとの数値矛盾: .claude/CLAUDE.md「レベル上限200以上（実質上限なし）」vs ai_development_rules.md「Lv255上限」。CLAUDE.md「STR/AGI」「MP」vs 正表記「ATK/SPD」「SP」。**どれが正か人間の裁定が必要（仮置き: コード実装のLv255とSP表記が正と推定）**。

## 修正方針

- AI_CONTEXTは「現状スナップショット」に徹する。恒久ルールはDOMAIN_RULESへ、履歴はUPDATE_LOGへ。
- Recent implementation state は**直近2〜4週間の変更だけ**を各1〜2行で保持し、それ以前の項目は内容を確認のうえ DOMAIN_RULES.md / FEATURE_STATUS.md へ移設して削除する（移設はCodexタスク化推奨。1回で全部やらず10項目ずつ）。

## 差し替え文面（ヘッダー〜Architectureまで）

```markdown
# AI_CONTEXT.md

Purpose: compressed current-state snapshot for ChatGPT and Codex.
Source of truth: repository code > docs/DOMAIN_RULES.md > this file.
Last updated: 2026-07-02
Branch: main

## Read order

1. AGENTS.md
2. docs/AI_CONTEXT.md (this file)
3. docs/CODEMAP.md
4. docs/FEATURE_STATUS.md
5. docs/DATA_MODEL.md if DB/types are involved
6. docs/DOMAIN_RULES.md if game rules/economy/progression are involved
7. docs/dev-os/ for task templates and QA checklists

## Stack

- App: Laravel 11 (PHP) + Livewire v3 + Blade + Alpine.js
- Styling: Tailwind CSS
- DB: MySQL (production on Xserver)
- Auth: Google OAuth, 1 account = 1 character
- Payment: Stripe (輝石 purchase)
- Tests: PHPUnit via `php artisan test`
- Deploy: `php local_deploy.php` (ZIP POST to valzeria.com), admin-only: `php local_deploy_admin.php`

## Product summary

ヴァルゼリアの冒険者 is a browser fantasy RPG recreating classic CGI-game FFA feel.
Core loop: login → battle/explore → EXP·Gold → level up → equip → job change → unlock next city → climb rankings.
Currencies: Gold (in-game), 輝石 (paid/support; paid and free tracked separately).
Main entities: character (1 per user), jobs, equipment (with 銘 affixes), materials, valmon (companion), monster marks, arena rankings, NPCs.
10 cities (アークレア→…→ヴァルゼリア城), 40+ dungeons (area 1-70 normal, 71-74 special, 75-83 街道).

## Important invariants

（現行の invariants 節をそのまま維持）
```

## Feature map の扱い

全行 `?` の表は削除し、docs/FEATURE_STATUS.md へ一本化する（同じ表を2箇所で持たない）。
FEATURE_STATUS.md の実態同期は別タスク（Codexに「コードを根拠にD/P/Nを埋める調査タスク」として依頼）。
