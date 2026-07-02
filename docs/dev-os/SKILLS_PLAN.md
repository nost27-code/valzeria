# Skills候補一覧と切り出し方針

Skills = 繰り返し使う「作業手順」。ルール（AGENTS.md）と知識（AI_CONTEXT/DOMAIN_RULES）は書かない。
各Skillは docs/dev-os/ のテンプレートを読み込んで実行する薄いラッパーにし、手順の本体はdev-os側に置く
（Codex・Claude・ChatGPTどれからでも同じテンプレを使えるようにするため）。

## 優先度A（すぐ作る価値がある）

### 1. /task-sheet — 実装指示書作成
- 入力: やりたいことの口頭説明
- 手順: IMPACT_MAP.md で影響範囲を特定 → CODEX_TASK_TEMPLATE.md を埋める → 空欄・仮置き箇所を人間に確認
- 出力: Codexにそのまま渡せる指示書
- トークン節約: テンプレ全文でなく該当セクションだけ展開

### 2. /qa-check — 実装後QA
- 入力: 変更diff（または変更ファイル一覧）
- 手順: 変更内容から段階1/2/3の該当を判定 → QA_CHECKLIST.md を消化 → ✅/❌/該当なしで報告 → ❌の修正案提示
- 注意: DB確認は実際にSELECTさせる。「保存されているはず」を認めない

### 3. /release-notes — 更新履歴3種作成
- 入力: 今回の変更内容（admin_update_summaries追記分 or diff）
- 手順: RELEASE_TEMPLATES.md に沿って 管理者サマリ→ユーザー告知→（目玉があれば）SNS/note を生成
- 注意: 弱体化の内部理由・確率の具体値を含めない検閲を最後に1回かける

## 優先度B（運用が回り始めてから）

### 4. /impact-check — 仕様変更の影響範囲出し
- IMPACT_MAP.md の該当行展開 + コード側の実参照（grep）で裏取りし、指示書の「既存仕様への影響」欄を生成

### 5. /image-request — 画像生成依頼文作成
- IMAGE_TEMPLATES.md の用途を選び、参考画像の指定と確認観点つき依頼文を出力

### 6. /spec-audit — 仕様矛盾チェック
- DOMAIN_RULES.md / AI_CONTEXT.md / CLAUDE.md / コードの数値・表記の食い違いを検出して報告
- 定期実行（月1目安）。今回見つかった「Lv200 vs 255」「MP vs SP」型の矛盾を早期検出する

### 7. /docs-sync — 実装後のdocs同期
- diffから AI_CONTEXT / DOMAIN_RULES / FEATURE_STATUS / CODEMAP の更新要否を判定し、最小diffで更新案を出す

## 作らないもの

- デプロイ実行Skill（人間の明示操作に残す。事故時の影響が大きすぎる）
- バランス数値を自動決定するSkill（数値は常に人間が決める）
- 本番DBを直接操作するSkill

## 実装形式のメモ

- Claude Code用: `.claude/skills/<name>/SKILL.md`
- Codex用: プロンプト先頭で「docs/dev-os/◯◯.md を読んで従う」と指示すれば同等（Skillsが使えない環境でもdev-osが本体なので機能する）
