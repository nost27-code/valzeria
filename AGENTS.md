# AGENTS.md

Scope: entire repository.

## Primary rule

Do not guess the current implementation state.
Use the repository as the source of truth.

Before changing gameplay, routes, data model, economy, auth, or UI flow, read:
- docs/AI_CONTEXT.md
- docs/CODEMAP.md
- docs/FEATURE_STATUS.md
- docs/DATA_MODEL.md when DB/types are involved
- docs/DOMAIN_RULES.md when game rules/balance are involved

## Source of truth

Two different questions have two different authorities:

- "How does it behave NOW?" (current behavior, DB schema, implemented state)
  → trust the actual code. Docs may be stale.
- "How SHOULD it behave?" (spec decisions, design policy, target rules)
  → trust docs/DOMAIN_RULES.md and explicit human rulings.
  Code may contain bugs or unadopted designs; code alone is evidence of current implementation, NOT proof of intended spec.

Conflict handling:
- Code vs spec docs conflict → do not silently align either side; report as 要裁定 and ask.
- docs/valzeria_spec.md vs docs/DOMAIN_RULES.md conflict → do not merge; report as 要裁定 and ask.
- Other docs/ files may be outdated; do not treat them as spec without confirmation.

Fixed rulings (2026-07-02):
- Player level cap is Lv255.
- Canonical stat labels for player-facing UI and new documents: HP / SP / ATK / DEF / MAG / SPR / SPD / LUK.
  MP / STR / AGI are legacy labels: fix them where players can see them, and do not use them in new docs.
  Do NOT rename DB columns (mp_base, str, agi, luck, job_level etc. stay as-is for now; renaming is a separate high-risk task).
- Job change requirement: Lv30+ and the required job mastered (current implementation is canonical).
  valzeria_spec.md's "Lv100" is an unadopted design — never apply it to code. Moving to Lv100 would be a separate rebalance task (転職条件再設計タスク).

## Work rules

- Prefer minimal diffs.
- Do not rewrite unrelated code.
- Do not change game rules, economy, naming, schema, or auth behavior unless requested.
- Do not mark a feature implemented unless code exists.
- Write `未確認` when the codebase does not prove a claim.
- Never invent balance numbers (drop rates, prices, EXP, probabilities). If the task sheet does not specify a number, ask; do not guess.
- Exclude generated/vendor dirs from manual inspection: vendor, node_modules, storage, public/build, coverage.
- Implementation tasks follow docs/dev-os/CODEX_TASK_TEMPLATE.md when a task sheet is provided.
- Before finishing, run the applicable sections of docs/dev-os/QA_CHECKLIST.md and include the results in the final report.
- For spec changes, consult docs/dev-os/IMPACT_MAP.md and list affected features in the plan.

## Implementation flow

1. Identify relevant files from docs/CODEMAP.md.
2. Inspect actual source files before editing.
3. Summarize plan before large or risky changes.
4. Implement with the smallest safe diff.
5. Run the narrowest relevant checks.
6. Report changed files, verification, and docs sync status.

## Docs sync

After each task, update docs only if the implementation state changed.

- Update docs/AI_CONTEXT.md for major behavior, architecture, route, UI flow, or feature availability changes.
- Update docs/FEATURE_STATUS.md when feature status changes.
- Update docs/CODEMAP.md when important files/routes/services are added, moved, or removed.
- Update docs/DATA_MODEL.md when schema, migrations, RLS, indexes, generated types, or important data contracts change.
- Update docs/DOMAIN_RULES.md when gameplay rules, balance, economy, progression, names, or monetization rules change.

Do not rewrite docs wholesale.
Prefer precise diffs.
If no docs update is needed, report:
Docs update: not needed — <reason>.

## Verification

- syntax: `php -l <changed files>`
- test: `php artisan test` (run the narrowest relevant tests)
- frontend build: `npm run build` (only when JS/CSS/Blade assets changed)
- migration check: run migration against local DB, then inspect representative rows
- manual: follow the 手動確認手順 in the task instruction sheet

If a command cannot run, report the reason and what was checked instead.

## Human approval required

Never execute these without explicit user approval in the current task:
- Destructive migrations (drop/rename/type-change on existing columns or tables)
- Seeder truncate / mass update against non-empty tables
- Changing master IDs (city/area/enemy/item/material/job)
- Anything touching Stripe, paid/free kiseki balance, or kiseki_transactions
- Deploy scripts (local_deploy.php / local_deploy_admin.php), server file deletion
- Deleting player-owned data (items, materials, valmons, progress)

For these, present the impact, risk, and rollback plan first, then wait.

## Admin update summary rule

Maintain `config/admin_update_summaries.php` as the source for the admin dashboard update summary.
The requested `src/data/adminUpdateSummaries.ts` path is not used because this repository is a Laravel/Blade app, not a TypeScript `src` app.
If this file is placed elsewhere in the future, follow the actual project path.

After each meaningful implementation task, decide whether to append one update summary entry.

Append an entry when the change affects:
- player-visible behavior
- admin-visible behavior
- UI flow
- exploration, battle, jobs, equipment, market, public logs, Valmon, ranking, billing, or economy
- balance values, rewards, drops, costs, limits, or progression
- user-impacting bugs
- DB schema, auth, permissions, or operational behavior

Do not append an entry for:
- typo-only changes
- formatting-only changes
- comments-only changes
- import cleanup
- lint-only changes
- AI docs-only changes
- refactors with no behavior change

When adding an entry:
- Add the newest entry at the top of the array.
- Use date format `YYYY-MM-DD`.
- Write Japanese text.
- Keep `title` short: 15〜35 Japanese characters.
- Write in player-facing language that an admin/operator can copy into public update notes.
- Use `internal` only for admin-only changes that should usually be excluded from player-facing copy.
- Do not include secrets, private user data, internal IDs, API keys, or sensitive implementation details.
- Use category: `added`, `changed`, `fixed`, `balance`, or `internal`.

If no entry is needed, report:
Admin update summary: not needed — <reason>.

## Final response format

- Summary
- Changed files
- Verification
- Docs update
- Admin update summary
- Risks / 未確認
