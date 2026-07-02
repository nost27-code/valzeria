# AGENTS.md 改訂案（**2026-07-02 適用済み**。以下は適用時の記録）

現行AGENTS.mdは骨格が良いので全面書き換えせず、以下の差分適用を推奨する。

## 解消すべき問題

1. **正仕様の二重定義**: docs/ai_development_rules.md は「正仕様は docs/valzeria_spec.md」、AGENTS.mdは「DOMAIN_RULES.md等」を指す。Codexがどちらを信じるか毎回ブレる。
2. **Verificationコマンドが `<fill>` のまま**: 検証手順が事実上未定義。
3. **ルールの三重管理**: AGENTS.md / docs/ai_development_rules.md / .claude/CLAUDE.md に重複ルールが分散。更新漏れで矛盾が育つ（例: Lv上限「200以上」vs「255」、STR/AGI vs ATK/SPD表記）。
4. dev-os（指示書テンプレ・QA）への参照がない。

## 適用手順（推奨）

1. AGENTS.md に下記パッチを適用
2. docs/ai_development_rules.md の中身のうちドメイン固有ルール（Gold経済・戦闘確認項目等）は DOMAIN_RULES.md / QA_CHECKLIST.md へ吸収済みか確認し、冒頭に「このファイルは AGENTS.md と docs/dev-os/ に統合済み。新規記載禁止」と追記
3. .claude/CLAUDE.md の数値仕様（Lv上限・ステ表記）を DOMAIN_RULES.md 参照に置き換え、数値の重複記載をやめる

## パッチ1: 正仕様チェーンの一本化（「Primary rule」節の直後に追加）

```markdown
## Source of truth order

When documents conflict, trust in this order:
1. Actual code + DB schema
2. docs/DOMAIN_RULES.md (game rules / balance)
3. docs/AI_CONTEXT.md (current state snapshot)
4. Everything else in docs/ (may be outdated; do not treat as spec without confirmation)

docs/valzeria_spec.md and other older spec files are historical references only.
```

## パッチ2: Verification節の具体化（`<fill>` を置換）

```markdown
## Verification

- syntax: `php -l <changed files>`
- test: `php artisan test` (run the narrowest relevant tests)
- frontend build check: `npm run build` (only when JS/CSS/Blade assets changed)
- migration check: run migration against local DB, then inspect representative rows
- manual: follow the 手動確認手順 in the task instruction sheet

If a command cannot run, report the reason and what was checked instead.
```

## パッチ3: dev-os参照の追加（「Work rules」節の末尾に追加）

```markdown
- Implementation tasks follow docs/dev-os/CODEX_TASK_TEMPLATE.md when a task sheet is provided.
- Before finishing, run the applicable sections of docs/dev-os/QA_CHECKLIST.md and include the results in the final report.
- For spec changes, consult docs/dev-os/IMPACT_MAP.md and list affected features in the plan.
```

## パッチ4: 破壊的操作の人間確認ルール（新節として追加）

```markdown
## Human approval required

Never execute these without explicit user approval in the current task:
- Destructive migrations (drop/rename/type-change on existing columns or tables)
- Seeder truncate / mass update against non-empty tables
- Changing master IDs (city/area/enemy/item/material/job)
- Anything touching Stripe, paid/free kiseki balance, or kiseki_transactions
- Deploy scripts (local_deploy.php / local_deploy_admin.php), server file deletion
- Deleting player-owned data (items, materials, valmons, progress)

For these, present the impact, risk, and rollback plan first, then wait.
```

## パッチ5: 数値バランスの扱い（「Work rules」に1行追加）

```markdown
- Never invent balance numbers (drop rates, prices, EXP, probabilities). If the task sheet does not specify a number, ask; do not guess.
```
