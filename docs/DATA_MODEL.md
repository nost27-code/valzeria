# DATA_MODEL.md

Purpose: compressed DB/data contract map.

| Entity/Table | Purpose | Key fields | Used by | Notes |
|---|---|---|---|---|
| users | <short> | <short> | <paths> | <short> |
| teams | <short> | <short> | <paths> | <short> |
| players | <short> | <short> | <paths> | <short> |
| contact_messages | Admin inquiry inbox entries from contact form and POP3 mailbox imports | `sender_email`, `subject`, `body`, `body_html`, `status`, `source`, `external_uid`, `received_at`, `attachment_path` | `ContactMessageManager`, `ContactMailboxImportService`, `AdminDashboard` | `body_html` is nullable and only populated for imported HTML email parts. |
| character_item_daily_supplies | Per-character daily supply depot ledger | `character_id`, `item_id`, `claimed_on`, `supplied_count`, `stocked_count` | `DailySupplyService`, `shop.supply` | `stocked_count` keeps the daily recovery-item allowance that could not be carried due to the 10 item carry target; the UI also shows today's unclaimed allowance as depot stock. |

## Rules

- Schema changes require migration.
- Type changes require generated/manual type update.
- RLS/security behavior must be verified when auth-sensitive.
