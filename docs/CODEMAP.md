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
| Battle | `app/Services/BattleService.php`, `app/Services/ExplorationDepthService.php`, `app/Services/Enemy/EnemyStatGenerationService.php`, `app/Services/Enemy/EnemyStatPreviewService.php` | Runs PvE battle and applies exploration danger/depth enemy stat scaling from generated enemy stats. |
| Market/shop | `app/Http/Controllers/ShopController.php`, `resources/views/shop/list.blade.php` | Equipment shop sales, sorting, owned counts, current equipment strip, and shop item cards. |
| Smith/enhancement | `app/Http/Controllers/SmithController.php`, `app/Services/EquipmentEnhancementService.php`, `resources/views/smith/enhance.blade.php`, `database/migrations/*equipment_enhancement*` | Equipment enhancement UI and +5 material recipe rules for weapons, armor, and accessories. |
| Drop weapon affixes | `app/Services/EquipmentAffixService.php`, `app/Services/DropService.php`, `app/Services/CharacterStatusService.php`, `app/Services/BattleService.php`, `app/Models/CharacterItem.php`, `database/migrations/*equipment_affix*`, `resources/views/equipment/partials/item-row.blade.php`, `resources/views/battle/result.blade.php` | Rolls named drop-weapon affixes, stores per-item stat/killer bonuses, applies equipped stat bonuses and PvE-only species killer damage, and displays affix names/effects. |
| Material exchange | `app/Http/Controllers/MaterialExchangeController.php`, `app/Services/MaterialExchangeService.php`, `resources/views/material-exchange/index.blade.php` | Converts materials, including enhancement fragments into stones and recovery item brewing. |
| Job change / temple | `app/Livewire/JobChange.php`, `resources/views/livewire/job-change.blade.php`, `app/Services/CharacterJobChangeService.php`, `app/Services/JobService.php` | Shows current job, job-change candidates, job detail modal, job-art/master bonus/requirements details, and executes job changes. |
| Public logs | `app/Services/PublicLogService.php`, `app/Livewire/ChatLog.php`, `resources/views/livewire/chat-log.blade.php` | Stores and displays bottom chat/public logs; private logs are scoped to sender/receiver and shown only on the private tab. |
| Admin | `app/Livewire/Admin/AdminDashboard.php`, `app/Livewire/Admin/AdminChatManager.php`, `resources/views/livewire/admin/admin-dashboard.blade.php`, `resources/views/livewire/admin/admin-chat-manager.blade.php`, `resources/views/components/layouts/admin.blade.php`, `config/admin_update_summaries.php` | Admin dashboard, admin chat posting, shared admin layout/navigation, and operator-facing update summary source. |
| Help/guide text | `app/Services/HelpContentService.php`, `config/help_content.php`, `app/Livewire/Admin/HelpTextManager.php`, `resources/views/livewire/admin/help-text-manager.blade.php`, `resources/views/help/index.blade.php`, `resources/views/town/guide/index.blade.php` | Shared help/案内所 content defaults and admin-editable overrides stored in `game_texts`. |
| Valmon | `app/Services/ValmonService.php`, `app/Services/BattleService.php`, `app/Services/ExplorationService.php`, `app/Services/DiscoveryService.php`, `resources/views/valmons/index.blade.php`, `resources/views/battle/result.blade.php` | Partner Valmon level effects, material find, exploration assist attack, hints, emergency recovery, ranch display. |
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
