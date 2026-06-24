# DATA_MODEL.md

Purpose: compressed DB/data contract map.

| Entity/Table | Purpose | Key fields | Used by | Notes |
|---|---|---|---|---|
| users | <short> | <short> | <paths> | <short> |
| teams | <short> | <short> | <paths> | <short> |
| players | <short> | <short> | <paths> | <short> |

## Rules

- Schema changes require migration.
- Type changes require generated/manual type update.
- RLS/security behavior must be verified when auth-sensitive.