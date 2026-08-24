<?php

namespace App\Services;

use App\Models\JobClass;
use App\Models\Skill;
use App\Support\JobArtEffectCatalog;

class JobCombatGuideService
{
    public function __construct(
        private readonly EquipmentPermissionService $equipmentPermissionService,
    ) {
    }

    /**
     * @return array{
     *     job_name: string,
     *     normal_attack_reference: string,
     *     weapon_labels: list<string>,
     *     armor_labels: list<string>,
     *     non_proficient_enabled: bool
     * }
     */
    public function summaryFor(JobClass $job): array
    {
        return [
            'job_name' => (string) $job->name,
            'normal_attack_reference' => $this->normalAttackReference((string) $job->normal_attack_type),
            'weapon_labels' => $this->equipmentPermissionService->nativeWeaponCategoryLabels((int) $job->id),
            'armor_labels' => $this->equipmentPermissionService->nativeArmorCategoryLabels((int) $job->id),
            'non_proficient_enabled' => $this->equipmentPermissionService->isNonProficientPenaltyEnabled(),
        ];
    }

    /**
     * @return array{
     *     job_name: string,
     *     normal_attack_reference: string,
     *     weapon_labels: list<string>,
     *     armor_labels: list<string>,
     *     non_proficient_enabled: bool,
     *     job_art_damage_references: array<int, string>
     * }
     */
    public function detailFor(JobClass $job): array
    {
        $job->loadMissing('jobArts');
        $summary = $this->summaryFor($job);
        $normalAttackType = (string) $job->normal_attack_type;
        $summary['job_art_damage_references'] = $job->jobArts
            ->mapWithKeys(function (Skill $skill) use ($normalAttackType): array {
                $reference = $this->damageReference($skill, $normalAttackType);

                return $reference === null ? [] : [(int) $skill->id => $reference];
            })
            ->all();

        return $summary;
    }

    public function normalAttackReference(?string $normalAttackType): string
    {
        return match ($this->normalizeNormalAttackType($normalAttackType)) {
            'magical' => '魔力参照',
            'adaptive' => '攻撃・魔力の高い方',
            default => '攻撃参照',
        };
    }

    public function damageReference(Skill $skill, ?string $normalAttackType = null): ?string
    {
        if ($skill->isJobArt()) {
            return $this->jobArtDamageReference($skill, $normalAttackType);
        }

        if ((float) $skill->power_multiplier <= 0) {
            return null;
        }

        return $this->damageTypeReference(
            (string) $skill->damage_type,
            (string) $skill->hybrid_scaling,
        );
    }

    private function jobArtDamageReference(Skill $skill, ?string $normalAttackType): ?string
    {
        $template = (string) $skill->effect_template;
        if (!JobArtEffectCatalog::dealsDamage($template)) {
            return null;
        }

        if ($template === 'DRAIN') {
            return $this->damageTypeReference(
                JobArtEffectCatalog::drainDamageType($skill->damage_type),
                (string) $skill->hybrid_scaling,
            );
        }

        if ($this->hasExplicitV2DamageRoute($skill)) {
            return $this->damageTypeReference(
                (string) $skill->damage_type,
                (string) $skill->hybrid_scaling,
            );
        }

        if (JobArtEffectCatalog::usesNormalAttackDamageType($template)) {
            return $this->normalAttackReference($normalAttackType);
        }

        return $this->damageTypeReference(
            JobArtEffectCatalog::damageType($template),
            (string) $skill->hybrid_scaling,
        );
    }

    private function hasExplicitV2DamageRoute(Skill $skill): bool
    {
        foreach (['job_art_v2_attack_stat', 'job_art_v2_defense_stat'] as $attribute) {
            if (trim((string) $skill->getAttribute($attribute)) !== '') {
                return true;
            }
        }

        return false;
    }

    private function damageTypeReference(string $damageType, string $hybridScaling): string
    {
        return match ($damageType) {
            'magical' => '魔力参照',
            'hybrid' => $hybridScaling === 'max'
                ? '攻撃・魔力の高い方'
                : '攻撃・魔力の平均',
            default => '攻撃参照',
        };
    }

    private function normalizeNormalAttackType(?string $normalAttackType): string
    {
        $normalAttackType = strtolower(trim((string) $normalAttackType));

        return in_array($normalAttackType, ['physical', 'magical', 'adaptive'], true)
            ? $normalAttackType
            : 'physical';
    }
}
