<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\Skill;
use App\Services\BattleEquipmentSummaryService;
use App\Services\CharacterIconSetService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentPermissionService;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtService;
use App\Services\JobArtV2DeckRoleResolution;
use App\Services\JobArtV2DeckRoleResolver;
use RuntimeException;

/** 正式出撃と試遊が同じ最終能力・ボス戦セット・装備効果を使うための開始snapshot。 */
final readonly class NationRaidPlayerPreparationService
{
    public function __construct(
        private CharacterStatusService $statusService,
        private JobArtService $jobArtService,
        private JobArtLineageCatalog $lineageCatalog,
        private JobArtV2DeckRoleResolver $deckRoleResolver,
        private BattleEquipmentSummaryService $equipmentSummaryService,
        private EquipmentPermissionService $permissions,
        private CharacterIconSetService $icons,
        private NationRaidRules $rules,
    ) {}

    /** @return array{abilities:array<string,int>,equipment:list<array<string,mixed>>,raid_resistance_rate:float,boss_set:list<array<string,mixed>>,boss_set_exact_identities:list<?string>,counterplay_enabled:bool} */
    public function capture(Character $character): array
    {
        CharacterStatusService::clearRequestCache((int) $character->id);
        $character->loadMissing('currentJob');
        $stats = $this->statusService->getFinalStats($character);
        foreach (['max_hp', 'max_mp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'] as $key) {
            if (! array_key_exists($key, $stats) || ! is_int($stats[$key])) {
                throw new RuntimeException("最終能力値 {$key} を取得できませんでした。");
            }
        }

        $arts = $this->jobArtService->battleArtsFor($character, 'boss');
        $identities = array_fill(0, 5, null);
        $set = array_fill(0, 5, null);
        foreach ($arts as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }
            $slot = (int) $skill->getAttribute('slot_no');
            if ($slot < 1 || $slot > 5) {
                throw new RuntimeException('ボス戦セットに範囲外のスロットがあります。');
            }
            $identity = JobArtV2DeckRoleResolution::artKey($skill);
            $lineage = $this->lineageCatalog->forArt($skill);
            $identities[$slot - 1] = $identity;
            $set[$slot - 1] = [
                'slot' => $slot,
                'skill_id' => (int) $skill->id,
                'name' => (string) $skill->name,
                'exact_identity' => $identity,
                'canonical_lineage' => $lineage['lineage_key'] ?? null,
                'raid_lineage' => isset($lineage['lineage_key'])
                    ? app(\App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter::class)->toRaid($lineage['lineage_key']) : null,
                'lineage_name' => $lineage['lineage_name'] ?? null,
                'is_counterplay' => $this->rules->counterplayArt($identity) !== null,
            ];
        }
        foreach (range(1, 5) as $slot) {
            $set[$slot - 1] ??= [
                'slot' => $slot,
                'skill_id' => null,
                'name' => '未設定',
                'exact_identity' => null,
                'canonical_lineage' => null,
                'raid_lineage' => null,
                'lineage_name' => null,
                'is_counterplay' => false,
            ];
        }

        $role = $this->deckRoleResolver->resolveSkills($character->current_job_id, $arts);
        $equipment = $this->equipmentSummaryService->forEnemy(
            $character,
            NationRaidRules::BOSS_SPECIES_KEY,
            NationRaidRules::BATTLE_TYPE,
        );

        $equipped = $character->characterItems()->where('is_equipped', true)
            ->with(['item', 'affixPrefix', 'affixSuffix'])->orderBy('id')->get();
        $weapon = $equipped->first(fn ($entry) => $entry->item?->type === 'weapon');
        $armor = $equipped->first(fn ($entry) => $entry->item?->type === 'armor');
        $effects = $weapon ? $this->permissions->effectiveKillerEffects($character, $weapon) : [];
        $rawKillerRate = array_sum(array_map(
            fn (array $effect): float => ($effect['species_key'] ?? null) === NationRaidRules::BOSS_SPECIES_KEY
                ? (float) $effect['damage_rate'] : 0.0,
            $effects,
        ));

        return [
            'actor' => [
                'name' => (string) $character->name,
                'level' => (int) $character->level,
                'stats' => $stats,
                'starting_hp' => $stats['max_hp'],
                'starting_mp' => $stats['max_mp'],
                'current_job_id' => $character->current_job_id,
                'job_key' => $character->currentJob?->key,
                'normal_attack_type' => $character->currentJob?->normal_attack_type,
                'sp_power_reference' => $stats['max_mp'],
                'sp_scaling_eligible' => true,
                'weapon_killer_effects' => $effects,
                'weapon_killer_species_key' => $weapon?->killer_species_key,
                'weapon_killer_damage_rate' => $weapon ? $this->permissions->effectiveKillerDamageRate($character, $weapon) : 0.0,
                'armor_resist_species_key' => $armor?->resist_species_key,
                'armor_species_damage_reduction_rate' => $armor ? $this->permissions->effectiveSpeciesDamageReductionRate($character, $armor) : 0.0,
                'job_art_activation_policy' => (string) ($character->job_art_activation_policy ?: 'normal'),
                'job_art_strategy' => $this->jobArtService->battleStrategy($character, $this->jobArtService->battleSlotContext('boss')),
                // Runtime属性（発動率・slot・条件）も保存。後からskills/slotを読み直さない。
                'job_arts' => $arts->map(fn (Skill $art): array => $art->getAttributes())->values()->all(),
            ],
            'character' => [
                'name' => (string) $character->name,
                'level' => (int) $character->level,
                'job_name' => (string) ($character->currentJob?->name ?? '職業未設定'),
                'battle_image_path' => $this->icons->pathFor($character, 'battle'),
            ],
            'killer_raw_rate' => $rawKillerRate,
            'killer_effective_rate' => NationRaidRules::raidKillerDamageRate($rawKillerRate),
            'abilities' => [
                'max_hp' => $stats['max_hp'],
                'max_sp' => $stats['max_mp'],
                'attack' => $stats['str'],
                'defense' => $stats['def'],
                'magic' => $stats['mag'],
                'spirit' => $stats['spr'],
                'agility' => $stats['agi'],
                'luck' => $stats['luk'],
            ],
            'equipment' => $equipment,
            'raid_resistance_rate' => max(array_column($equipment, 'resist_rate') ?: [0.0]),
            'boss_set' => array_values($set),
            'boss_set_exact_identities' => $identities,
            'counterplay_enabled' => $role->active && $this->requiredJobArtFlagsEnabled(),
        ];
    }

    private function requiredJobArtFlagsEnabled(): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false);
    }

}
