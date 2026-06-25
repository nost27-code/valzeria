# CODEMAP.md

Purpose: find relevant files quickly. Do not duplicate implementation details.

## App structure

| Concern | Files/dirs | Notes |
|---|---|---|
| Routes/pages | <path> | <short> |
| UI components | <path> | <short> |
| Server actions/API | <path> | <short> |
| DB client | <path> | <short> |
| Auth | <path> | <short> |
| Game logic | <path> | <short> |
| Exploration | <path> | <short> |
| Battle | <path> | <short> |
| Market | <path> | <short> |
| Public logs | <path> | <short> |
| Admin | <path> | <short> |
| Portal online count | `app/Console/Commands/SendPortalOnlineCount.php`, `app/Livewire/Admin/AdminDashboard.php`, `routes/console.php`, `config/services.php` | Sends and displays `characters.last_seen_at >= now()-5min` count for ポチゲーポータル/admin metrics. |
| Admin contact inbox | `app/Livewire/Admin/ContactMessageManager.php`, `resources/views/livewire/admin/contact-message-manager.blade.php`, `app/Services/ContactMailboxImportService.php`, `app/Livewire/Admin/AdminDashboard.php`, `resources/views/components/layouts/admin.blade.php`, `routes/web.php` | Imports and displays form/POP3 inquiries; POP3 HTML parts are stored in `contact_messages.body_html` and previewed from the admin inbox. Admin pages poll `/admin/contact-messages/badge-count` every 5 minutes and show new-message count on the favicon. |
| Local sandbox | `.env.local.example`, `scripts/local-setup.ps1`, `scripts/local-dev.ps1`, `docs/LOCAL_DEVELOPMENT.md` | Safe local startup path using SQLite, log mail, disabled portal sending, and empty external service keys. |
| Daily supply depot | `app/Services/DailySupplyService.php`, `resources/views/shop/supply.blade.php`, `app/Http/Controllers/ShopController.php`, `database/migrations/2026_06_25_040000_add_stocked_count_to_character_item_daily_supplies.php` | Grants daily recovery item allowances and keeps unclaimed remainder as 補給所 stock. |
| Home actions / notifications | `app/Services/HomeActionService.php`, `resources/views/livewire/home-action-panel.blade.php`, `app/Services/CharacterNotificationService.php`, `app/Livewire/CityHeader.php` | Shows next-action prompts and unread notification bell entries. |
| Rank battle | `app/Services/PvPBattleService.php`, `app/Models/ArenaRanking.php`, `app/Models/ArenaLog.php`, `app/Livewire/ColosseumScreen.php` | Handles arena rank battle results, rank swaps, logs, and rank-down notifications. |

## Ignore

- node_modules
- .next
- dist
- build
- coverage
