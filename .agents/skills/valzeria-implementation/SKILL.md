---
name: valzeria-implementation
description: Use for implementing Valzeria features, bug fixes, UI changes, API changes, and gameplay changes.
---

# Valzeria implementation workflow

Goal: implement the requested change with minimal safe diffs.

## Before editing

1. Read AGENTS.md.
2. Read docs/AI_CONTEXT.md.
3. Use docs/CODEMAP.md to locate relevant files.
4. If DB/types are involved, read docs/DATA_MODEL.md.
5. If gameplay/economy/progression is involved, read docs/DOMAIN_RULES.md.
6. Inspect actual source files before changing code.

## Plan

For risky or multi-file changes, report:
- target files
- intended change
- risks
- verification plan

## Implement

- Prefer smallest safe diff.
- Keep existing architecture.
- Do not refactor unrelated code.
- Do not change balance/schema/auth unless requested.
- Preserve Japanese domain terms exactly.

## Verify

Run the narrowest relevant checks:
- typecheck/lint/test/build if available
- manual route/component reasoning if commands cannot run

## Finish

Report:
- Summary
- Changed files
- Verification
- Docs update
- Risks / 未確認