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
| Battle | `app/Services/BattleService.php`, `app/Services/ChampBattleService.php`, `app/Services/ExplorationDepthService.php`, `app/Services/CharacterPowerService.php`, `app/Services/Enemy/EnemyStatGenerationService.php`, `app/Services/Enemy/EnemyStatPreviewService.php` | Runs PvE/champ battle, applies exploration danger/depth enemy stat scaling from generated enemy stats, and formats player-facing combat power guidance. |
| Market/shop | `app/Http/Controllers/ShopController.php`, `resources/views/shop/list.blade.php` | Equipment shop sales, sorting, owned counts, current equipment strip, and shop item cards. |
| Smith/enhancement | `app/Http/Controllers/SmithController.php`, `app/Services/EquipmentEnhancementService.php`, `resources/views/smith/enhance.blade.php`, `database/migrations/*equipment_enhancement*` | Equipment enhancement UI and +5 material recipe rules for weapons, armor, and accessories. |
| Drop equipment affixes | `app/Services/EquipmentAffixService.php`, `app/Services/DropService.php`, `app/Services/CharacterStatusService.php`, `app/Services/BattleService.php`, `app/Models/CharacterItem.php`, `database/migrations/*equipment_affix*`, `resources/views/equipment/partials/item-row.blade.php`, `resources/views/battle/result.blade.php` | Rolls named drop weapon/armor affixes, stores per-item stat/killer/resist bonuses, applies equipped stat bonuses, PvE-only weapon species killer damage, and PvE-only armor species damage reduction. |
| Material exchange | `app/Http/Controllers/MaterialExchangeController.php`, `app/Services/MaterialExchangeService.php`, `resources/views/material-exchange/index.blade.php` | Converts materials, including enhancement fragments into stones and recovery item brewing. |
| Job change / temple | `app/Livewire/JobChange.php`, `resources/views/livewire/job-change.blade.php`, `app/Services/CharacterJobChangeService.php`, `app/Services/JobService.php` | Shows current job, job-change candidates, job detail modal, job-art/master bonus/requirements details, and executes job changes. |
| Tavern NPCs | `app/Http/Controllers/TavernController.php`, `app/Services/TavernNpcService.php`, `app/Models/NpcMaster.php`, `resources/views/tavern/*.blade.php`, `public/images/npc/npc_*.webp` | Shows daily tavern NPCs, talk pages, roster/detail pages, and NPC portraits mapped from `npc_id`. |
| NPC procurement and market loop | `app/Http/Controllers/NpcProcurementRequestController.php`, `app/Services/NpcProcurementRequestService.php`, `app/Services/NpcProcurementRequestGenerationService.php`, `app/Services/NpcMarketListingService.php`, `app/Console/Commands/GenerateNpcMarketListings.php`, `app/Models/NpcMaterialStock.php`, `app/Livewire/Admin/NpcMarketAnalyticsManager.php`, `resources/views/market/*.blade.php`, `resources/views/livewire/admin/npc-market-analytics-manager.blade.php` | Links procurement requests to tavern NPCs, stores delivered materials as NPC stock, generates scheduled NPC market listings, and shows admin NPC procurement/market analytics. |
| Public logs | `app/Services/PublicLogService.php`, `app/Livewire/ChatLog.php`, `app/Livewire/Admin/PublicLogManager.php`, `resources/views/livewire/chat-log.blade.php`, `resources/views/components/layouts/facility.blade.php`, `resources/views/livewire/admin/public-log-manager.blade.php` | Stores/displays bottom chat/public logs on home and battle-related facility screens; private logs are scoped to sender/receiver on the player side, and admins can search/select/delete public log rows. |
| Admin | `app/Livewire/Admin/AdminDashboard.php`, `app/Livewire/Admin/AdminChatManager.php`, `app/Livewire/Admin/PublicLogManager.php`, `app/Livewire/Admin/OperatorAnalyticsManager.php`, `resources/views/livewire/admin/admin-dashboard.blade.php`, `resources/views/livewire/admin/admin-chat-manager.blade.php`, `resources/views/livewire/admin/public-log-manager.blade.php`, `resources/views/livewire/admin/operator-analytics-manager.blade.php`, `resources/views/components/layouts/admin.blade.php`, `config/admin_update_summaries.php` | Admin dashboard, admin chat posting, public log management, operator analytics, shared admin layout/navigation, and operator-facing update summary source. |
| Help/guide text | `app/Services/HelpContentService.php`, `config/help_content.php`, `app/Livewire/Admin/HelpTextManager.php`, `resources/views/livewire/admin/help-text-manager.blade.php`, `resources/views/help/index.blade.php`, `resources/views/town/guide/index.blade.php` | Shared help/案内所 content defaults and admin-editable overrides stored in `game_texts`. |
| Valmon | `app/Services/ValmonService.php`, `app/Services/BattleService.php`, `app/Services/ExplorationService.php`, `app/Services/DiscoveryService.php`, `resources/views/valmons/index.blade.php`, `resources/views/battle/result.blade.php` | Partner Valmon level effects, material find, exploration assist attack, hints, emergency recovery, ranch display. |
| Portal online count | `app/Console/Commands/SendPortalOnlineCount.php`, `app/Livewire/Admin/AdminDashboard.php`, `routes/console.php`, `config/services.php` | Sends and displays `characters.last_seen_at >= now()-5min` count for ポチゲーポータル/admin metrics. |
| Admin contact inbox | `app/Livewire/Admin/ContactMessageManager.php`, `resources/views/livewire/admin/contact-message-manager.blade.php`, `app/Services/ContactMailboxImportService.php`, `app/Livewire/Admin/AdminDashboard.php`, `resources/views/components/layouts/admin.blade.php`, `routes/web.php` | Imports and displays form/POP3 inquiries; POP3 HTML parts are stored in `contact_messages.body_html` and previewed from the admin inbox. Admin pages poll `/admin/contact-messages/badge-count` every 5 minutes and show new-message count on the favicon. |
| Local sandbox | `.env.local.example`, `scripts/local-setup.ps1`, `scripts/local-dev.ps1`, `docs/LOCAL_DEVELOPMENT.md` | Safe local startup path using SQLite, log mail, disabled portal sending, and empty external service keys. |
| Daily supply depot | `app/Services/DailySupplyService.php`, `resources/views/shop/supply.blade.php`, `app/Http/Controllers/ShopController.php`, `database/migrations/2026_06_25_040000_add_stocked_count_to_character_item_daily_supplies.php` | Grants daily recovery item allowances and keeps unclaimed remainder as 補給所 stock. |
| Home actions / notifications | `app/Services/HomeActionService.php`, `resources/views/livewire/home-action-panel.blade.php`, `app/Services/CharacterNotificationService.php`, `app/Livewire/CityHeader.php` | Shows next-action prompts and unread notification bell entries. |
| Rank battle | `app/Services/PvPBattleService.php`, `app/Services/ArenaNpcBattleService.php`, `app/Services/ArenaNpcAutoBattleService.php`, `app/Services/ArenaNpcRankingService.php`, `app/Console/Commands/RunArenaNpcAutoBattles.php`, `app/Models/ArenaRanking.php`, `app/Models/ArenaNpcRanking.php`, `app/Models/ArenaLog.php`, `app/Models/ArenaNpcLog.php`, `app/Models/ArenaNpcAutoLog.php`, `app/Livewire/ColosseumScreen.php`, `app/Livewire/ColosseumRanking.php` | Handles player and NPC arena rank battle results, scheduled NPC rank battles, rank swaps, logs, and rank-down notifications. |

## Ignore

- node_modules
- .next
- dist
- build
- coverage
