---
name: valzeria-review
description: Use to review Valzeria changes before commit or PR, focusing on regressions, game rules, DB safety, auth, and docs sync.
---

# Valzeria review workflow

Goal: find correctness, safety, and consistency issues before commit.

## Review checklist

Check:
- requested behavior is implemented
- unrelated behavior was not changed
- auth/admin boundaries are preserved
- DB/schema/type changes are consistent
- game balance was not changed accidentally
- public logs do not leak private data
- UI flow still matches existing patterns
- docs sync was handled or explicitly skipped

## Evidence

Base findings on code.
Use `未確認` when not proven.

## Output

Return:
- Blockers
- Non-blocking issues
- Suggested fixes
- Verification gaps
- Docs sync status