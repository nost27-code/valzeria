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

## Work rules

- Prefer minimal diffs.
- Do not rewrite unrelated code.
- Do not change game rules, economy, naming, schema, or auth behavior unless requested.
- Do not mark a feature implemented unless code exists.
- Write `未確認` when the codebase does not prove a claim.
- Exclude generated/vendor dirs from manual inspection: node_modules, .next, dist, build, coverage.

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

Use project-defined commands when available:
- install: <fill>
- dev: <fill>
- lint: <fill>
- typecheck: <fill>
- test: <fill>
- build: <fill>

If a command cannot run, report the reason and what was checked instead.

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
