# AI_CONTEXT.md

Purpose: compressed current-state snapshot for ChatGPT and Codex.
Source of truth: repository code + docs below.
Last updated: YYYY-MM-DD
Branch: <branch>
Commit: <commit>

## Read order

For implementation planning:
1. AGENTS.md
2. docs/AI_CONTEXT.md
3. docs/CODEMAP.md
4. docs/FEATURE_STATUS.md
5. docs/DATA_MODEL.md if DB/types are involved
6. docs/DOMAIN_RULES.md if game rules/economy/progression are involved

## Status legend

D = implemented
P = partially implemented
N = not implemented
? = unverified
X = deprecated/removed

## Stack

- App: <Next.js / React / etc>
- Language: <TypeScript / etc>
- DB: <Supabase / PostgreSQL / etc>
- Auth: <fill>
- Styling: <fill>
- Tests: <fill>
- Deploy: <fill>

## Product summary

ヴァルゼリア is a browser-based pro-baseball GM / RPG-style web game.
Core loop: <one-line summary>
Primary currencies/resources: <Gold / 輝石 / etc>
Main player entities: <user/team/player/valmon/etc>

## Current feature map

| Area | St | Main code | Notes |
|---|---:|---|---|
| Auth | ? | see CODEMAP | 未確認 |
| Home/dashboard | ? | see CODEMAP | 未確認 |
| Exploration | ? | see CODEMAP | 未確認 |
| Battle | ? | see CODEMAP | 未確認 |
| Jobs/class change | ? | see CODEMAP | 未確認 |
| Equipment | ? | see CODEMAP | 未確認 |
| Market | ? | see CODEMAP | 未確認 |
| Public logs | ? | see CODEMAP | 未確認 |
| Valmon | ? | see CODEMAP | 未確認 |
| Admin | ? | see CODEMAP | 未確認 |
| Billing/輝石 | ? | see CODEMAP | 未確認 |

## Architecture notes

- Routing: <short>
- Server/API pattern: <short>
- State management: <short>
- DB access pattern: <short>
- Auth/session pattern: <short>
- Logging pattern: <short>

## Important invariants

- Do not change economy balance without explicit request.
- Do not change DB schema without migration/type update.
- Do not expose admin-only data to normal users.
- Public logs must not leak private/internal data.
- Feature status must reflect code, not intention.

## Known gaps / 未確認

- <gap 1>
- <gap 2>
- <gap 3>

## Recent implementation state

Keep this section short. Current state only, not changelog.

- <current fact 1>
- <current fact 2>
- <current fact 3>