---
name: valzeria-doc-sync
description: Use after Valzeria implementation tasks to update AI_CONTEXT, FEATURE_STATUS, CODEMAP, DATA_MODEL, or DOMAIN_RULES only when needed.
---

# Valzeria docs sync workflow

Goal: keep shared AI docs accurate and compact.

## Decide

Update docs only if the change affects shared implementation context.

Use:
- docs/AI_CONTEXT.md for current high-level state
- docs/FEATURE_STATUS.md for feature status
- docs/CODEMAP.md for file/route/service locations
- docs/DATA_MODEL.md for DB/schema/types/RLS
- docs/DOMAIN_RULES.md for game rules/economy/progression

## Rules

- Do not write changelog-style history.
- Do not duplicate source code.
- Do not add speculation.
- Use `未確認` for unverified facts.
- Prefer tables and short bullets.
- Keep existing structure.
- Make precise diffs only.

## Status codes

D = implemented
P = partially implemented
N = not implemented
? = unverified
X = deprecated/removed

## Update log sync

After implementation, check whether docs/UPDATE_LOG.md should be updated.

Update it only for meaningful changes:
- new feature
- user-visible UI/behavior change
- game rule or balance change
- user-impacting bug fix
- important admin/auth/DB/operation change

Do not update it for tiny code cleanup or AI docs-only changes.

Write entries under `## Unreleased` in Japanese.
Prefer short bullets grouped by:
- Added
- Changed
- Fixed
- Balance
- Internal

Report either:
- Update log: updated docs/UPDATE_LOG.md
- Update log: not needed — <reason>

## Finish

Report one of:
- Docs update: updated <files>
- Docs update: not needed — <reason>