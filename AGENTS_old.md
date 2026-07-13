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

- Current behavior/schema: trust code. Intended spec/rules: trust docs/DOMAIN_RULES.md + human rulings (code alone is not proof of intended spec).
- Any conflict (code vs docs, or valzeria_spec vs DOMAIN_RULES): do not pick a side — report as 要裁定 and ask.
- Fixed rulings (2026-07-02, details in docs/valzeria_spec.md): Lv cap 255; player-facing labels HP/SP/ATK/DEF/MAG/SPR/SPD/LUK (MP/STR/AGI are legacy; never rename DB columns); job change = Lv30+ and required job mastered (Lv100 is unadopted — never apply).

## Work rules

- Prefer minimal diffs.
- Do not rewrite unrelated code.
- Do not change game rules, economy, naming, schema, or auth behavior unless requested.
- Do not mark a feature implemented unless code exists.
- Write `未確認` when the codebase does not prove a claim.
- Never invent balance numbers (drop rates, prices, EXP, probabilities); if unspecified, ask.
- Exclude generated/vendor dirs from manual inspection: vendor, node_modules, storage, public/build, coverage.
- Do not put battle/reward/unlock calculation logic in Blade views or bloat Controllers; keep it in the Service layer.
- When looking up an enemy/material/entity that shares a name across dungeons, disambiguate with `area_id`/`dungeon_id` (composite key), never by name alone.
- When a task sheet is provided, follow it and docs/dev-os/ (templates, QA checklist, impact map).

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

Never do these without explicit approval in the current task — present impact, risk, and rollback first, then wait:
destructive migrations; Seeder truncate/mass update; changing master IDs; anything touching Stripe or kiseki balances/transactions; running deploy scripts or deleting server files; deleting player-owned data.

## Admin update summary rule

After each meaningful task, append one entry to `config/admin_update_summaries.php` (newest first) if the change affects player/admin-visible behavior, gameplay/economy/balance, user-impacting bugs, or DB/auth/operations. Skip for typo/format/comment/lint/refactor/AI-docs-only changes.

Entry format: `date` YYYY-MM-DD; `category` added/changed/fixed/balance/internal (`internal` = admin-only); `title` 15〜35字の日本語; `detail` = player-facing Japanese an operator can copy into public notes. No secrets, private user data, or internal IDs.

If no entry is needed, report: Admin update summary: not needed — <reason>.

## Final response format

- Summary
- Changed files
- Verification
- Docs update
- Admin update summary
- Risks / 未確認
