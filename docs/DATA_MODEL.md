# DATA_MODEL.md

Purpose: compressed DB/data contract map.

| Entity/Table | Purpose | Key fields | Used by | Notes |
|---|---|---|---|---|
| users | <short> | <short> | <paths> | <short> |
| teams | <short> | <short> | <paths> | <short> |
| players | <short> | <short> | <paths> | <short> |
| contact_messages | Admin inquiry inbox entries from contact form and POP3 mailbox imports | `sender_email`, `subject`, `body`, `body_html`, `status`, `source`, `external_uid`, `received_at`, `attachment_path` | `ContactMessageManager`, `ContactMailboxImportService`, `AdminDashboard` | `body_html` is nullable and only populated for imported HTML email parts. |
| character_item_daily_supplies | Per-character daily supply depot ledger | `character_id`, `item_id`, `claimed_on`, `supplied_count`, `stocked_count` | `DailySupplyService`, `shop.supply` | `stocked_count` keeps recovery-item allowance that could not be carried due to the 10 item carry target; the UI shows carried stock, today's stocked amount, and today's unclaimed allowance as the depot breakdown. |
| character_exploration_states | Current exploration chain state | `character_id`, `area_id`, `exploration_point`, `chain_count`, `danger_rate`, `valmon_material_found`, `valmon_heal_used` | `ExplorationStateService`, `ExplorationService`, `ValmonService` | `valmon_heal_used` limits Lv75 Valmon emergency recovery to once per exploration chain. |
| game_texts | Admin-editable UI/help text overrides | `key`, `value`, `description` | `GameTextService`, `FacilityTextManager`, `HelpTextManager`, `HelpContentService` | Help/案内所 keys use the `help.*` prefix; missing rows fall back to config defaults. |
| items | Equipment and item master | `type`, `max_enhance`, `affix_enabled`, stat bonus columns, rank metadata | `EquipmentEnhancementService`, `EquipmentAffixService`, shop/equipment views | Weapons, armor, and accessories with `max_enhance > 0` are currently raised to max +5 by enhancement balance migration. Enemy drop weapons with `affix_enabled=true` can roll affixes when granted as drops. |
| equipment_affix_prefixes / equipment_affix_suffixes | Drop weapon affix masters | prefix `affix_key`, stat/rate/weight; suffix `species_key`, name, killer rate/weight | `EquipmentAffixService`, `DropService` | Seeder/migration-backed masters for weapon prefix stat bonuses and species killer suffixes. |
| character_items | Per-character owned equipment instances | `affix_*_bonus`, `affix_quality`, `killer_species_key`, `killer_damage_rate`, `affix_generated_at` | `CharacterItem`, `EquipmentAffixService`, `CharacterStatusService`, `BattleService` | Affix bonuses are stored on the dropped equipment instance, apply only while equipped, and species killer damage is PvE-only. |
| weapon_enhancement_recipes / armor_enhancement_recipes | Enhancement material recipes | weapon `materials` JSON, armor `required_material_*`, enhancement level | `EquipmentEnhancementService` | Weapon and armor +1..+5 use 1/3/5/7/9 stones. Accessory +1..+5 uses `ACC0008` 装飾強化石 in service code because no accessory recipe table exists. |
| titles | Master title definitions | `name`, `category`, `unlock_type`, `target_type`, `target_id` | `TitleUnlockService`, `ValmonService` | `名相棒` uses `unlock_type=valmon_level`, `target_id=100` and is granted when a partner Valmon reaches Lv100. |

## Rules

- Schema changes require migration.
- Type changes require generated/manual type update.
- RLS/security behavior must be verified when auth-sensitive.
