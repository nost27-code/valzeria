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

## Final response format

- Summary
- Changed files
- Verification
- Docs update
- Risks / 未確認