<?php

namespace App\Services;

use App\Models\Skill;
use App\Support\JobArtEffectCatalog;

final class JobArtV2LoadoutPresenter
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtLineageCatalog $lineageCatalog,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtV2FieldCatalog $fieldCatalog,
        private readonly JobArtV2PowerResolver $powerResolver,
        private readonly JobArtV2DamageSemanticsResolver $damageSemanticsResolver,
        private readonly JobArtV2EffectSemanticsResolver $effectSemanticsResolver,
        private readonly ?JobArtV2RoleEffectCatalog $roleEffectCatalog = null,
        private readonly ?JobArtV2ProgressionCatalog $progressionCatalog = null,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        private readonly ?JobArtV2CDesignCatalog $cDesignCatalog = null,
        private readonly ?JobArtV2CDesignEffectCatalog $cDesignEffectCatalog = null,
        private readonly ?JobArtV2CDesignClassificationCatalog $cDesignClassificationCatalog = null,
        private readonly ?JobArtV2UltimateCounterplayCatalog $ultimateCounterplayCatalog = null,
        private readonly ?JobArtV2CardDescriptionCatalog $cardDescriptionCatalog = null,
    ) {}

    public function enabledForCurrentJob(?int $currentJobId): bool
    {
        return $this->featureGate->usesLoadoutUiForCurrentJob($currentJobId);
    }

    public function cDesignEnabledForCurrentJob(?int $currentJobId): bool
    {
        return $this->featureGate->usesCDesignPrototypeForCurrentJob($currentJobId);
    }

    /** @return array<string, mixed>|null */
    public function cDesignLoadoutSummary(?int $currentJobId, iterable $skills): ?array
    {
        if (! $this->cDesignEnabledForCurrentJob($currentJobId)) {
            return null;
        }

        $skills = collect($skills)->filter(fn ($skill): bool => $skill instanceof Skill)->values();
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveSkills($currentJobId, $skills);
        $counts = ['equipped' => $skills->count()];

        return [
            'active' => $resolution->active,
            'valid' => $skills->isEmpty() || $resolution->isValid(),
            'main_lineage_key' => null,
            'main_lineage_name' => null,
            'secondary_lineage_key' => null,
            'secondary_lineage_name' => null,
            'counts' => $counts,
            'secondary_gain' => ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
                ->secondaryProducerGain(),
        ];
    }

    /**
     * @param  iterable<Skill>  $arts
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     catch: string,
     *     description: string,
     *     suited_for: string,
     *     ultimate_outlook: string,
     *     traits: array<int, string>,
     *     steps: array<int, array{role_label: string, art_name: ?string, conditional_priority: bool}>,
     *     priority_note: string,
     *     job_note: ?string
     * }>
     */
    public function recommendationsForCurrentJob(?int $currentJobId, iterable $arts): array
    {
        if (! $this->enabledForCurrentJob($currentJobId)) {
            return [];
        }

        $jobMetadata = $this->prototypeCatalog->jobResourceMetadata($currentJobId);
        if ($jobMetadata === null) {
            return [];
        }

        $trustedArtsByRank = [];
        foreach ($arts as $art) {
            if ($this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $art)) {
                $trustedArtsByRank[(int) $art->learn_rank] = $art;
            }
        }

        $resourceName = (string) $jobMetadata['resource_name'];
        $consumerMinimum = $this->minimumResourcePoints($currentJobId, 5, 4);
        $finisherMinimum = $this->minimumResourcePoints($currentJobId, 9, 12);

        return [
            [
                'key' => 'finisher',
                'name' => '決着型',
                'catch' => '奥義まで力を温存',
                'description' => $currentJobId === 69
                    ? '通常攻撃や現在職技で指揮点を貯め、12ptを温存して奥義を狙う戦型です。'
                    : '始動戦技を重ねてリソースを温存し、強力な奥義を狙う戦型です。',
                'suited_for' => '長めの戦闘・ボス戦',
                'ultimate_outlook' => '狙いやすい',
                'traits' => ['奥義重視', '奥義を狙いやすい', '連携戦技は温存されやすい'],
                'steps' => $currentJobId === 69
                    ? $this->commandPrioritySteps('finisher', $trustedArtsByRank)
                    : $this->prioritySteps([1, 5, 9], $trustedArtsByRank),
                'priority_note' => $currentJobId === 69
                    ? "戦技を使わない手番を作り、通常攻撃や現在職技で{$resourceName}を貯めます。{$resourceName}{$finisherMinimum}ptなど条件成立後、最初の候補は奥義が優先されます。"
                    : "始動が使用可能な間は連携より先に判定されるため、{$resourceName}を温存して奥義を狙います。{$resourceName}{$finisherMinimum}ptなど条件成立後、最初の候補は奥義が優先されます。",
                'job_note' => $this->jobSpecificRecommendation(
                    $currentJobId,
                    'finisher',
                    $resourceName,
                    $trustedArtsByRank,
                    $consumerMinimum,
                    $finisherMinimum,
                ),
            ],
            [
                'key' => 'cycle',
                'name' => '循環型',
                'catch' => '連携戦技を繰り返す',
                'description' => $currentJobId === 69
                    ? '通常攻撃や現在職技で指揮点を補充し、4ptごとに連携戦技を繰り返して戦う戦型です。'
                    : 'リソースを連携戦技へ積極的に使い、連携戦技を繰り返して戦う戦型です。',
                'suited_for' => '中～長期戦',
                'ultimate_outlook' => '狙いにくい',
                'traits' => ['連携重視', '継続火力', '奥義は狙いにくい'],
                'steps' => $currentJobId === 69
                    ? $this->commandPrioritySteps('cycle', $trustedArtsByRank)
                    : $this->prioritySteps([5, 1, 9], $trustedArtsByRank),
                'priority_note' => $currentJobId === 69
                    ? "{$resourceName}{$consumerMinimum}pt以上なら連携を先に使用し、不足時は通常攻撃や現在職技の手番で補充します。"
                    : "{$resourceName}{$consumerMinimum}pt以上ある時は連携を先に使用し、不足時は始動で補充します。",
                'job_note' => $this->jobSpecificRecommendation(
                    $currentJobId,
                    'cycle',
                    $resourceName,
                    $trustedArtsByRank,
                    $consumerMinimum,
                    $finisherMinimum,
                ),
            ],
            [
                'key' => 'counter',
                'name' => '対策型',
                'catch' => '必要な時だけ割り込ませる',
                'description' => '条件付き継承戦技を始動戦技より前に置き、必要な場面だけ割り込ませる戦型です。',
                'suited_for' => '危険状態や相手への対策が必要な戦闘',
                'ultimate_outlook' => '構成次第',
                'traits' => ['対応重視', '条件成立時だけ優先', '相手に合わせた構築'],
                'steps' => [
                    ['role_label' => '条件戦技', 'art_name' => null, 'conditional_priority' => false],
                    ['role_label' => '始動', 'art_name' => $this->artName($trustedArtsByRank, 1), 'conditional_priority' => false],
                    ['role_label' => '連携／奥義', 'art_name' => null, 'conditional_priority' => true],
                ],
                'priority_note' => '条件が成立した時だけ前方の継承戦技が先に使用されます。信頼できる条件戦技がない場合は、具体的な戦技を推測して配置しません。',
                'job_note' => null,
            ],
        ];
    }

    /**
     * @return array{
     *     role_key: string,
     *     role_label: string,
     *     role_description: string,
     *     card_description: string,
     *     origin_key: string,
     *     origin_label: string,
     *     source_lineage_key: ?string,
     *     source_lineage_name: ?string,
     *     source_badge: string,
     *     resource_text: ?string,
     *     effect_texts: array<int, string>,
     *     field_texts: array<int, string>,
     *     stance_texts: array<int, string>,
     *     priority_text: ?string,
     *     is_ultimate: bool,
     *     effective_power: int,
     *     effective_hit_count: int,
     *     damage_category: ?string,
     *     effect_template: string,
     *     effect_label: string,
     *     legacy_effect_copy_suppressed: bool
     * }|null
     */
    public function forArt(?int $currentJobId, Skill $skill, ?iterable $loadoutSkills = null): ?array
    {
        if (! $this->enabledForCurrentJob($currentJobId) || ! $skill->isJobArt()) {
            return null;
        }

        $metadata = $this->resourceCatalog->forArt($skill);
        $resourcesEnabled = $this->featureGate->usesResourcesForCurrentJob($currentJobId);
        $isTrustedCurrentJobArt = $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill);
        $origin = (string) $skill->getAttribute('job_art_origin');
        $isTrustedCurrentOrigin = $isTrustedCurrentJobArt
            && ($origin === '' || $origin === 'current');
        $originKey = $isTrustedCurrentOrigin ? 'current' : 'inherited';
        $originLabel = $originKey === 'current' ? '現在職' : '継承';
        $normalizedOrigin = $originKey === 'current' ? 'current' : 'inherited';
        $deckRoleResolution = $loadoutSkills !== null
            && $this->featureGate->usesCDesignPrototypeForCurrentJob($currentJobId)
                ? ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
                    ->resolveSkills($currentJobId, $loadoutSkills)
                : null;
        $deckRole = $deckRoleResolution?->roleFor($skill);
        // 系譜はカード自身の種類であり、現在職・主副・装備前後で効果を
        // 減らさない。信頼済み戦技は一覧表示の時点から常に全文・全効果を示す。
        $formalCDesignLineage = $resourcesEnabled
            && $this->prototypeCatalog->isTrustedArtProfile($skill);
        $techCDesignCard = false;
        $primaryResourceMetadata = $resourcesEnabled
            ? $this->resourceCatalog->forCurrentJobArt($currentJobId, $skill, $normalizedOrigin)
            : null;
        $isSameLineageInherited = $originKey === 'inherited'
            && ($formalCDesignLineage
                ? $deckRole === JobArtV2DeckRole::MAIN
                : $this->prototypeCatalog->isSamePrimaryLineage($currentJobId, $skill));
        $isPortableFieldOrigin = $originKey === 'inherited'
            && $this->fieldCatalog->isPortableFieldArt($currentJobId, $skill);
        $sourceLineage = $this->lineageCatalog->forArt($skill);
        $sourceLineageName = $sourceLineage['lineage_name'] ?? null;
        $role = $this->roleForDisplay($skill, $primaryResourceMetadata ?? $metadata);
        $progressionCatalog = $this->progressionCatalog ?? app(JobArtV2ProgressionCatalog::class);
        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        $roleEffectMetadata = $roleCatalog->forArt($skill);
        $progressionMechanicsAllowed = $deckRoleResolution?->active === true
            ? $formalCDesignLineage
            : ($isTrustedCurrentOrigin || $isSameLineageInherited);
        $displayDamageSemantics = $this->damageSemanticsResolver->forDisplay(
            $currentJobId,
            $skill,
            $formalCDesignLineage,
        );
        $cDesignEffectMetadata = $formalCDesignLineage
            ? ($this->cDesignEffectCatalog ?? app(JobArtV2CDesignEffectCatalog::class))->forArt($skill)
            : null;
        if ($formalCDesignLineage && is_array($cDesignEffectMetadata)) {
            $roleEffectMetadata = array_replace_recursive($roleEffectMetadata ?? [], $cDesignEffectMetadata);
        } elseif ($techCDesignCard
            && ! ($this->cDesignCatalog ?? app(JobArtV2CDesignCatalog::class))->allowsTechBaseRoleMetadata($skill)
        ) {
            $roleEffectMetadata = null;
        }
        $usesAdaptiveDamageRoute = is_array($roleEffectMetadata['adaptive_route'] ?? null);
        $usesExplicitDamageDescription = is_array($roleEffectMetadata['damage_stat_route'] ?? null)
            || (bool) ($roleEffectMetadata['use_normal_attack_damage_type'] ?? false);
        $usesRoleDefenseIgnore = (int) ($roleEffectMetadata['damage_stat_route']['defense_ignore_percent'] ?? 0) > 0;
        $displayEffectTemplate = ($techCDesignCard
                ? ($this->cDesignCatalog ?? app(JobArtV2CDesignCatalog::class))->techReplacementTemplate($skill)
                : null)
            ?? $progressionCatalog->replacementTemplateForDisplay($skill, $progressionMechanicsAllowed)
            ?? (is_string($cDesignEffectMetadata['replacement_template'] ?? null)
                ? (string) $cDesignEffectMetadata['replacement_template']
                : null)
            ?? $this->effectSemanticsResolver->replacementEffectTemplateForDisplay($currentJobId, $skill)
            ?? ($resourcesEnabled && $roleCatalog->isPortable($skill)
                ? $roleCatalog->replacementTemplate($skill)
                : null)
            ?? (string) $skill->effect_template;
        if (($displayDamageSemantics['damage_category'] ?? null) === 'magical') {
            $displayEffectTemplate = 'MAGICAL_DAMAGE';
        }
        $legacyEffectCopySuppressed = $displayEffectTemplate !== (string) $skill->effect_template
            || ($resourcesEnabled && $roleCatalog->isPortable($skill) && $roleCatalog->suppressesLegacyEffect($skill));
        $semanticsJobId = $formalCDesignLineage ? (int) $skill->job_id : $currentJobId;
        $effectTexts = $resourcesEnabled && ($isTrustedCurrentOrigin || $formalCDesignLineage)
            ? $this->effectTexts($semanticsJobId, $skill, $metadata ?? [])
            : [];
        if ($resourcesEnabled) {
            if ($roleCatalog->isPortable($skill)
                && (! $techCDesignCard
                    || ($this->cDesignCatalog ?? app(JobArtV2CDesignCatalog::class))->allowsTechBaseRoleMetadata($skill))
            ) {
                $effectTexts = array_values(array_unique([
                    ...$effectTexts,
                    ...$roleCatalog->effectTexts($skill),
                ]));
            }
            $effectTexts = array_values(array_unique([
                ...$effectTexts,
                ...$progressionCatalog->effectTextsForDisplay(
                    $skill,
                    $progressionMechanicsAllowed,
                    $formalCDesignLineage,
                ),
            ]));
            if ((bool) config('battle.job_art_v2.ultimate_counterplay', false)) {
                $counterplayCatalog = $this->ultimateCounterplayCatalog
                    ?? app(JobArtV2UltimateCounterplayCatalog::class);
                $counterplayText = $counterplayCatalog->effectText($skill);
                $potentialFormalCounterplay = $formalCDesignLineage
                    || ($deckRoleResolution === null
                        && $this->prototypeCatalog->isSamePrimaryLineage($currentJobId, $skill));
                if ($counterplayText !== null && $potentialFormalCounterplay) {
                    $effectTexts[] = $counterplayText;
                }
                $potentialMainRankNine = (int) $skill->learn_rank === 9
                    && ($deckRole === JobArtV2DeckRole::MAIN
                        || ($deckRoleResolution === null
                            && $this->prototypeCatalog->isSamePrimaryLineage($currentJobId, $skill)));
                if ($potentialMainRankNine) {
                    $effectTexts[] = '［奥義準備］この奥義が使う資源が必要量に達すると予告する。相手の次の1行動後に発動可能になる。奥義実行または準備中断後は、資源が必要量に達していれば再び予告する';
                }
                $effectTexts = array_values(array_unique($effectTexts));
            }
        }

        $displayPower = $this->powerResolver->forDisplay($currentJobId, $skill, $loadoutSkills);
        $effectivePower = $resourcesEnabled && $roleCatalog->isPortable($skill)
            ? ($roleCatalog->executionPower($skill) ?? $displayPower)
            : $displayPower;
        $effectiveHitCount = $progressionCatalog->hitCountForDisplay($skill, $progressionMechanicsAllowed);
        $effectTexts = $this->roleEffectTextsForDisplay(
            $skill,
            $roleEffectMetadata,
            $effectTexts,
            $effectivePower,
            $originKey,
        );
        $displayResourceMetadata = $resourcesEnabled ? $primaryResourceMetadata : null;
        $roleDescription = $this->roleDescription(
            $skill,
            $role,
            $displayResourceMetadata,
            $displayEffectTemplate,
            $usesAdaptiveDamageRoute,
        );
        $resourceText = $primaryResourceMetadata !== null
            ? $this->resourceText($role, $primaryResourceMetadata)
            : null;
        $fieldTexts = ($isTrustedCurrentOrigin || $isPortableFieldOrigin || $formalCDesignLineage) && $metadata !== null
            ? $this->fieldTexts($semanticsJobId, $skill, $metadata, $formalCDesignLineage)
            : [];
        $stanceTexts = ($isTrustedCurrentOrigin || $formalCDesignLineage) && ! $usesRoleDefenseIgnore
            ? $this->stanceTexts($semanticsJobId, $skill, $metadata ?? [], $formalCDesignLineage)
            : [];
        $priorityText = null;
        if ($resourcesEnabled && $role === ResourceRole::FINISHER && $primaryResourceMetadata !== null) {
            $resourceName = trim((string) ($primaryResourceMetadata['resource_name'] ?? '')) ?: '系譜リソース';
            $requiredPoints = max(
                (int) ($primaryResourceMetadata['resource_cost_points'] ?? 0),
                (int) ($primaryResourceMetadata['minimum_resource_points'] ?? 0),
            );
            $priorityText = "{$resourceName}が{$requiredPoints}ある場合、セット順より先にこの奥義の発動判定を行う";
        }

        $effectTexts = $this->playerFacingTexts($effectTexts);
        $fieldTexts = $this->playerFacingTexts($fieldTexts);
        $stanceTexts = $this->playerFacingTexts($stanceTexts);
        $priorityText = $priorityText !== null ? $this->playerFacingText($priorityText) : null;
        $resourceText = $resourceText !== null ? $this->playerFacingText($resourceText) : null;

        $additionalNumericEffects = array_values(array_filter(
            $skill->jobArtNumericEffectLabels($effectivePower, $displayEffectTemplate, $effectiveHitCount),
            static fn (string $label): bool => preg_match('/^(?:威力\s+|\d+Hit$)/u', $label) !== 1,
        ));
        $additionalNumericEffects = $this->withMasterDuration($skill, $additionalNumericEffects);
        if ($legacyEffectCopySuppressed) {
            $additionalNumericEffects = $this->preservedNumericEffects(
                $additionalNumericEffects,
                $roleEffectMetadata,
            );
        }
        $additionalNumericEffects = $this->playerFacingTexts($additionalNumericEffects);

        $usesCanonicalDescription = false;
        $cardDescription = $this->cardDescription(
            $skill,
            $role,
            $displayResourceMetadata,
            $displayEffectTemplate,
            [...$fieldTexts, ...$effectTexts, ...$stanceTexts, ...$additionalNumericEffects],
            $priorityText,
            $legacyEffectCopySuppressed,
            $effectiveHitCount,
            $usesAdaptiveDamageRoute,
            $usesExplicitDamageDescription,
        );
        if ($techCDesignCard) {
            $normalizedBaseEffect = ($this->cDesignCatalog ?? app(JobArtV2CDesignCatalog::class))
                ->normalizedBaseEffect($skill);
            if (is_string($normalizedBaseEffect) && trim($normalizedBaseEffect) !== '') {
                $cardDescription = $this->playerFacingText($normalizedBaseEffect);
            }
        }
        if ($this->prototypeCatalog->isTrustedArtProfile($skill)) {
            $defaultDescription = ($this->cardDescriptionCatalog ?? app(JobArtV2CardDescriptionCatalog::class))
                ->defaultDescription($skill);
            if ($defaultDescription !== null) {
                $cardDescription = $defaultDescription;
                $usesCanonicalDescription = true;
            }
        }
        $displayDescriptionSource = $cardDescription;
        if (! $usesCanonicalDescription
            && $resourcesEnabled
            && $deckRoleResolution === null
        ) {
            // The available-art list has no resolved deck role. Keep its reviewed
            // canonical copy instead of rebuilding cross-lineage text from legacy master fields.
            $displayDescriptionSource = ($this->cardDescriptionCatalog ?? app(JobArtV2CardDescriptionCatalog::class))
                ->defaultDescription($skill)
                ?? $displayDescriptionSource;
        }
        $displayDescription = $usesCanonicalDescription
            ? $cardDescription
            : $this->withDamagePower(
                $displayDescriptionSource,
                $displayEffectTemplate,
                $effectivePower,
                $effectiveHitCount,
            );
        $deckRoleDisplay = $this->cDesignDeckRoleDisplay(
            $currentJobId,
            $skill,
            $deckRoleResolution,
        );

        return [
            'role_key' => $role->value,
            'role_label' => $this->roleLabel($role),
            'role_description' => $roleDescription,
            'card_description' => $cardDescription,
            'display_description' => $displayDescription,
            'origin_key' => $originKey,
            'origin_label' => $originLabel,
            'source_lineage_key' => $sourceLineage['lineage_key'] ?? null,
            'source_lineage_name' => $sourceLineageName,
            'source_lineage_icon_path' => $this->lineageCatalog->iconPathForKey(
                $sourceLineage['lineage_key'] ?? null,
            ),
            'lineage_relation' => $isTrustedCurrentOrigin
                ? 'current'
                : ($isSameLineageInherited ? 'same_lineage' : 'cross_lineage'),
            'source_badge' => $sourceLineageName ?? '戦技',
            'resource_text' => $resourceText,
            'effect_texts' => $effectTexts,
            'field_texts' => $fieldTexts,
            'stance_texts' => $stanceTexts,
            'priority_text' => $priorityText,
            'is_ultimate' => $role === ResourceRole::FINISHER,
            'effective_power' => $effectivePower,
            'effective_hit_count' => $effectiveHitCount,
            'damage_category' => $displayDamageSemantics['damage_category'] ?? null,
            'effect_template' => $displayEffectTemplate,
            'effect_label' => JobArtEffectCatalog::label($displayEffectTemplate) ?? $skill->jobArtEffectLabel(),
            'legacy_effect_copy_suppressed' => $legacyEffectCopySuppressed,
            'deck_role_key' => $deckRoleDisplay['key'],
            'deck_role_label' => $deckRoleDisplay['label'],
            'deck_role_note' => $deckRoleDisplay['note'],
            'deck_role_is_actual' => $deckRoleDisplay['is_actual'],
            'portability_key' => $deckRoleDisplay['portability_key'],
            'portability_label' => $deckRoleDisplay['portability_label'],
            'deck_block_reason' => $deckRoleDisplay['block_reason'],
        ];
    }

    private function withDamagePower(
        string $description,
        string $effectTemplate,
        int $effectivePower,
        int $effectiveHitCount,
    ): string {
        if ($effectivePower <= 0
            || ! JobArtEffectCatalog::dealsDamage($effectTemplate)
            || preg_match('/(?:合計)?威力\s*\d+(?:\.\d+)?%/u', $description) === 1
        ) {
            return $description;
        }

        $power = $this->compactNumber($effectivePower);
        $hitCount = max(1, $effectiveHitCount);

        if ($hitCount > 1) {
            $powered = preg_replace(
                '/(\d+)回の((?:物理|魔力)?ダメージ)を与える/u',
                "合計威力{$power}%の$2を$1回与える",
                $description,
                1,
                $count,
            );
            if ($count > 0 && is_string($powered)) {
                return $powered;
            }

            $powered = preg_replace(
                '/相手に(?:自分|自身)の通常攻撃と同じ種類のダメージを(\d+)回/u',
                "相手に通常攻撃と同じ種類で、合計威力{$power}%のダメージを$1回",
                $description,
                1,
                $count,
            );
            if ($count > 0 && is_string($powered)) {
                return $powered;
            }

            $powered = preg_replace(
                '/(物理|魔力)ダメージ/u',
                "合計威力{$power}%の{$hitCount}回の$1ダメージ",
                $description,
                1,
                $count,
            );
            if ($count > 0 && is_string($powered)) {
                return $powered;
            }
        }

        $powered = preg_replace(
            '/相手に(?:自分|自身)の通常攻撃と同じ種類のダメージ/u',
            "相手に通常攻撃と同じ種類で、威力{$power}%のダメージ",
            $description,
            1,
            $count,
        );
        if ($count > 0 && is_string($powered)) {
            return $powered;
        }

        $powered = preg_replace(
            '/(物理|魔力)ダメージ/u',
            "威力{$power}%の$1ダメージ",
            $description,
            1,
            $count,
        );

        return $count > 0 && is_string($powered) ? $powered : $description;
    }

    /** @return array<string, int|float|string|bool>|null */
    private function resolvedCDesignResourceMetadata(Skill $skill, ?JobArtV2DeckRole $deckRole): ?array
    {
        $metadata = $this->resourceCatalog->forArt($skill);
        if ($metadata === null
            || ! in_array($deckRole, [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY], true)
        ) {
            return null;
        }

        $metadata['resource_origin'] = $deckRole->value;
        $metadata['is_primary_resource'] = $deckRole === JobArtV2DeckRole::MAIN;
        if ($deckRole === JobArtV2DeckRole::SECONDARY
            && ($metadata['resource_role'] ?? null) === ResourceRole::PRODUCER->value
        ) {
            $metadata['resource_gain_points'] = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
                ->secondaryProducerGain();
        }

        return $metadata;
    }

    /**
     * @return array{key:string,label:string,note:string,is_actual:bool,portability_key:string,portability_label:string,block_reason:?string}
     */
    private function cDesignDeckRoleDisplay(
        ?int $currentJobId,
        Skill $skill,
        ?JobArtV2DeckRoleResolution $resolution,
    ): array {
        return [
            'key' => 'full_effect',
            'label' => '全効果',
            'note' => '習得済みなら現在職に関係なく、カードに書かれた効果がすべて有効です。',
            'is_actual' => $resolution?->active === true,
            'portability_key' => 'full_effect',
            'portability_label' => '全職で使用可',
            'block_reason' => $resolution?->blockReasonFor($skill),
        ];
    }

    /** @param array<string, int|float|string|bool>|null $metadata */
    private function roleForDisplay(Skill $skill, ?array $metadata): ResourceRole
    {
        $configured = $metadata['resource_role'] ?? null;
        if (is_string($configured) && ($role = ResourceRole::tryFrom($configured)) !== null) {
            return $role;
        }

        return match ((int) $skill->learn_rank) {
            1 => ResourceRole::PRODUCER,
            5 => ResourceRole::CONSUMER,
            9 => ResourceRole::FINISHER,
            default => ResourceRole::NEUTRAL,
        };
    }

    private function roleLabel(ResourceRole $role): string
    {
        return match ($role) {
            ResourceRole::PRODUCER => '始動',
            ResourceRole::CONSUMER => '連携',
            ResourceRole::NEUTRAL => '対策',
            ResourceRole::FINISHER => '奥義',
        };
    }

    private function roleDescription(
        Skill $skill,
        ResourceRole $role,
        ?array $resourceMetadata,
        string $effectTemplate,
        bool $usesAdaptiveDamageRoute = false,
    ): string
    {
        $action = $usesAdaptiveDamageRoute
            ? $this->adaptiveRouteActionDescription(polite: true)
            : $this->actionDescription($skill, $effectTemplate);
        if ($resourceMetadata === null || $role === ResourceRole::NEUTRAL) {
            return $this->asSentence($action);
        }

        $resourceName = trim((string) ($resourceMetadata['resource_name'] ?? '')) ?: '系譜リソース';
        $gain = max(0, (int) ($resourceMetadata['resource_gain_points'] ?? 0));
        $cost = max(0, (int) ($resourceMetadata['resource_cost_points'] ?? 0));
        $minimum = max($cost, (int) ($resourceMetadata['minimum_resource_points'] ?? $cost));

        $copy = $action;
        if ($role === ResourceRole::PRODUCER && $gain > 0) {
            $copy = match ((string) ($resourceMetadata['resource_gain_event'] ?? ResourceEvent::JOB_ART_CAST->value)) {
                ResourceEvent::JOB_ART_HIT->value => "{$action}。HITすると{$resourceName}を+{$gain}します",
                ResourceEvent::HP_SP_CONVERSION_SUCCESS->value => "この戦技のSP消費後、最大HPの5%をHP1未満にならない範囲で消費し、最大SPの5%を回復します。HP消費とSP回復が両方成立すると{$resourceName}を+{$gain}します",
                default => "{$resourceName}を+{$gain}し、{$action}",
            };
        }

        if (in_array($role, [ResourceRole::CONSUMER, ResourceRole::FINISHER], true) && $cost > 0) {
            $resourceCopy = $minimum > $cost
                ? "{$resourceName}が{$minimum}以上ある時、{$resourceName}を-{$cost}し"
                : "{$resourceName}を-{$cost}し";
            $copy = "{$resourceCopy}、{$action}";
        }

        return $this->asSentence($copy);
    }

    /**
     * @param  array<string, int|float|string|bool>|null  $resourceMetadata
     * @param  array<int, string>  $effectDetails
     */
    private function cardDescription(
        Skill $skill,
        ResourceRole $role,
        ?array $resourceMetadata,
        string $effectTemplate,
        array $effectDetails,
        ?string $priorityText,
        bool $legacyEffectCopySuppressed,
        int $effectiveHitCount,
        bool $usesAdaptiveDamageRoute = false,
        bool $usesExplicitDamageDescription = false,
    ): string {
        $normalizedEffectDetails = [];
        foreach ($effectDetails as $effectDetail) {
            if (preg_match('/^被ダメージ\s*-(\d+)%$/u', $effectDetail, $matches) === 1) {
                $effectDetail = $effectTemplate === 'DAMAGE_GUARD_BARRIER'
                    ? "次の自分の行動開始まで、受けるダメージを{$matches[1]}%軽減する"
                    : "次の直接攻撃のダメージを{$matches[1]}%軽減する（1回）";
            }
            $effectDetail = $this->effectDetailSentence($effectDetail);
            if ($effectDetail !== '') {
                $normalizedEffectDetails[] = $effectDetail;
            }
        }
        if (in_array('追加の自己強化はない', $normalizedEffectDetails, true)
            && in_array('各Hitで会心判定を行う', $normalizedEffectDetails, true)
        ) {
            $normalizedEffectDetails = array_values(array_filter(
                $normalizedEffectDetails,
                static fn (string $detail): bool => ! in_array($detail, ['追加の自己強化はない', '各Hitで会心判定を行う'], true),
            ));
            $normalizedEffectDetails[] = '各Hitで会心判定を行い、追加の自己強化は行わない';
        }
        if (in_array($effectTemplate, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $normalizedEffectDetails = $this->orderHealCleanseDetails($normalizedEffectDetails);
        }
        if ($effectTemplate === 'SELF_BUFF') {
            $normalizedEffectDetails = $this->orderSharedSelfBuffDetails($normalizedEffectDetails);
        }

        $omitGenericAction = (($usesAdaptiveDamageRoute || $usesExplicitDamageDescription) && $normalizedEffectDetails !== [])
            || ($effectTemplate === 'V2_ROLE_EFFECT_ONLY' && $normalizedEffectDetails !== [])
            || ($effectTemplate === 'SELF_BUFF' && $normalizedEffectDetails !== [])
            || ($effectTemplate === 'ENEMY_DEBUFF'
                && $this->hasConcreteStatChangeDetail($normalizedEffectDetails))
            || ($this->isRewardOnlyTemplate($effectTemplate)
                && $this->hasConcreteRewardDetail($normalizedEffectDetails))
            || (in_array($effectTemplate, ['HEAL', 'HEAL_CLEANSE'], true)
                && $this->hasConcreteHealDetail($normalizedEffectDetails));
        $clauses = $this->cardActionClauses(
            $skill,
            $role,
            $resourceMetadata,
            $effectTemplate,
            $omitGenericAction,
            $effectiveHitCount,
        );

        $caveats = [];
        $clauses = array_values(array_filter($clauses, static function (string $clause) use (&$caveats): bool {
            if (str_starts_with($clause, '異系譜への継承では')) {
                $caveats[] = $clause;

                return false;
            }

            return true;
        }));

        $effectCaveats = [];
        foreach ($normalizedEffectDetails as $effectDetail) {
            if (str_starts_with($effectDetail, '追加の軽減効果はない')) {
                $effectCaveats[] = $effectDetail;

                continue;
            }

            $clauses[] = $effectDetail;
        }

        if ($priorityText !== null) {
            $clauses[] = $this->effectDetailSentence($priorityText);
        }

        if (count($effectDetails) === 0 && ! $legacyEffectCopySuppressed) {
            $clauses = [...$clauses, ...$this->memoEffectClauses($skill, $effectTemplate)];
        }

        $clauses = $this->mergeAdjacentRecoveryClauses($clauses);
        $clauses = $this->mergeAdjacentRewardClauses($clauses);
        $clauses = $this->mergeAdjacentStatChangeClauses($clauses);
        $clauses = $this->mergeLeadingResourceClause($clauses);
        $clauses = array_map($this->clarifyOneTurnDuration(...), $clauses);
        $clauses = [...$clauses, ...$caveats, ...$effectCaveats];

        $clauses = array_values(array_filter(array_unique(array_map(
            fn (string $clause): string => $this->sentenceFragment($clause),
            $clauses,
        ))));

        if ($clauses === []) {
            return '戦況に応じた効果を発動する。';
        }

        $description = array_shift($clauses).'。';
        foreach ($clauses as $clause) {
            $description .= $this->cardClauseTransition($clause, $effectTemplate).'。';
        }

        return $description;
    }

    /**
     * @param  array<int, string>  $details
     * @return array<int, string>
     */
    private function orderHealCleanseDetails(array $details): array
    {
        usort($details, static function (string $left, string $right): int {
            $priority = static function (string $detail): int {
                if (str_contains($detail, 'HPを回復する')) {
                    return 0;
                }
                if (str_contains($detail, 'SP') && str_contains($detail, '回復する')) {
                    return 1;
                }
                if (str_contains($detail, '浄化する')) {
                    return 2;
                }

                return 3;
            };

            return $priority($left) <=> $priority($right);
        });

        return $details;
    }

    /**
     * @param  array<int, string>  $details
     * @return array<int, string>
     */
    private function orderSharedSelfBuffDetails(array $details): array
    {
        return [
            ...array_values(array_filter(
                $details,
                static fn (string $detail): bool => str_starts_with($detail, '自分の通常攻撃が'),
            )),
            ...array_values(array_filter(
                $details,
                static fn (string $detail): bool => ! str_starts_with($detail, '自分の通常攻撃が'),
            )),
        ];
    }

    private function cardClauseTransition(string $clause, string $effectTemplate): string
    {
        if (str_starts_with($clause, '使用後、')) {
            return 'その後、'.mb_substr($clause, mb_strlen('使用後、'));
        }

        if (preg_match('/^(?:星光|旋律|聖域|静寂|天測)の場を\d+ターン展開する$/u', $clause) === 1) {
            return '同時に、'.$clause;
        }

        foreach ([
            '異系譜への継承では',
            '異系譜では',
            '追加の自己強化はない',
            '追加加護なし',
            '追加の軽減効果はない',
            '有害状態は解除しない',
            '星印を増減しない',
            '生成した場はこの戦技自身に適用しない',
            'Gold補正なし',
        ] as $prefix) {
            if (str_starts_with($clause, $prefix)) {
                return 'ただし、'.$clause;
            }
        }

        if (preg_match('/が\d+ある場合、セット順より先にこの奥義の発動判定を行う$/u', $clause) === 1
            || preg_match('/(?:会心判定|各Hit|Hit（)/u', $clause) === 1
            || str_starts_with($clause, '直前の自分の行動後に物理攻撃を受けていた場合')
        ) {
            return $clause;
        }

        if (preg_match('/^相手の精神を\d+%無視/u', $clause) === 1) {
            return 'このとき、'.$clause;
        }

        foreach ([
            '魔法型または不死系の相手には',
            '既存',
            'Gold判定',
            '素材判定',
            'Gold補正',
            'Drop補正',
            'HP回復 ',
            '最大HP回復 ',
            '最大SP回復 ',
            '最大SPの',
            '被ダメージ ',
            '剣気集中の準備効果',
            '崩し印は',
            '照準8以上で使用し',
            '触媒4消費・',
            '触媒12消費・',
            '標準変成:',
            '標準装填:',
            '指揮点 +4',
            '途中で別行動をしても維持',
        ] as $prefix) {
            if (str_starts_with($clause, $prefix)) {
                return $prefix === '魔法型または不死系の相手には'
                    ? 'また、'.$clause
                    : 'その後、'.$clause;
            }
        }

        foreach ([
            'この攻撃',
            '残存割合が低い側の回復量',
            '相手の物理防御',
            '物理攻撃・命中率',
            '命中 +',
            '基礎MISS',
            '物理経路と魔法経路',
            '行動開始時',
            '直前の自分の行動後',
            '蒼天構え成立時',
            '主場あり：',
            '構え中:',
            '構え時：',
            '連携使用済みなら',
            '連携由来の封技成立済み',
            '防御準備中の敵なら',
            '回避補正',
            '必中寄り',
            '単体大魔法',
            '無属性特大魔法',
            '攻撃+精神参照',
            '攻撃+魔力',
            '攻撃/魔力',
            '敏捷/運',
            '敏捷依存',
            '敵単体',
            '敵バリア',
            '敵回避補正',
            '敵精神を一部無視',
            '敵防御/精神無視',
            '自身敏捷',
            '魔力参照',
            '魔法型/不死系',
            '運依存',
            '小ダメージ',
            '3回攻撃',
        ] as $prefix) {
            if (str_starts_with($clause, $prefix)) {
                return 'このとき、'.$clause;
            }
        }

        foreach ([
            'HITした場合',
            'HIT時',
            '星光の場を展開',
            '旋律の場を展開',
            '聖域の場を展開',
            '5種類の場を固定順で次へ張り替え',
            '貫通構えを取る',
            '蒼天構えを',
        ] as $prefix) {
            if (str_starts_with($clause, $prefix)) {
                return in_array($prefix, ['HITした場合', 'HIT時'], true)
                    ? $clause
                    : '同時に、'.$clause;
            }
        }

        if ($effectTemplate === 'SELF_BUFF'
            && preg_match('/(?:を[+\-]\d+%|ターンの間)/u', $clause) === 1
        ) {
            return '具体的には、'.$clause;
        }

        return 'その後、'.$clause;
    }

    /**
     * @param  array<string, int|float|string|bool>|null  $resourceMetadata
     * @return array<int, string>
     */
    private function cardActionClauses(
        Skill $skill,
        ResourceRole $role,
        ?array $resourceMetadata,
        string $effectTemplate,
        bool $omitGenericAction = false,
        int $effectiveHitCount = 1,
    ): array {
        $action = $omitGenericAction ? '' : $this->plainActionDescription($skill, $effectTemplate, $effectiveHitCount);
        if ($resourceMetadata === null || $role === ResourceRole::NEUTRAL) {
            return $action !== '' ? [$action] : [];
        }

        $resourceName = trim((string) ($resourceMetadata['resource_name'] ?? '')) ?: '系譜リソース';
        $gain = max(0, (int) ($resourceMetadata['resource_gain_points'] ?? 0));
        $cost = max(0, (int) ($resourceMetadata['resource_cost_points'] ?? 0));
        $minimum = max($cost, (int) ($resourceMetadata['minimum_resource_points'] ?? $cost));

        $clauses = [];
        if ($role === ResourceRole::PRODUCER && $gain > 0) {
            $clauses = match ((string) ($resourceMetadata['resource_gain_event'] ?? ResourceEvent::JOB_ART_CAST->value)) {
                ResourceEvent::JOB_ART_HIT->value => array_values(array_filter([$action, "HITした場合、{$resourceName}を+{$gain}する"])),
                ResourceEvent::HP_SP_CONVERSION_SUCCESS->value => array_values(array_filter([
                    $action,
                    'この戦技のSP消費後、最大HPの5%をHP1未満にならない範囲で消費し、最大SPの5%を回復する',
                    "HP消費とSP回復が両方成立した場合、{$resourceName}を+{$gain}する",
                ])),
                default => [$action === ''
                    ? "{$resourceName}を+{$gain}する"
                    : "{$resourceName}を+{$gain}し、{$action}"],
            };
        }

        if (in_array($role, [ResourceRole::CONSUMER, ResourceRole::FINISHER], true) && $cost > 0) {
            if ($action === '') {
                $clauses = [$minimum > $cost
                    ? "{$resourceName}が{$minimum}以上ある時、{$resourceName}を-{$cost}する"
                    : "{$resourceName}を-{$cost}する"];
            } else {
                $resourceCondition = $minimum > $cost
                    ? "{$resourceName}が{$minimum}以上ある時、{$resourceName}を-{$cost}し"
                    : "{$resourceName}を-{$cost}し";

                $clauses = ["{$resourceCondition}、{$action}"];
            }
        }

        if ($clauses === [] && $action !== '') {
            $clauses[] = $action;
        }

        return $clauses;
    }

    /**
     * @param  array<int, string>  $clauses
     * @return array<int, string>
     */
    private function mergeLeadingResourceClause(array $clauses): array
    {
        if (count($clauses) < 2
            || preg_match('/^(.+を[+\-]\d+)する$/u', $clauses[0], $matches) !== 1
            || str_starts_with($clauses[1], '異系譜への継承では')
        ) {
            return $clauses;
        }

        $clauses[0] = $matches[1].'し、'.$clauses[1];
        unset($clauses[1]);

        return array_values($clauses);
    }

    /**
     * @param  array<int, string>  $clauses
     * @return array<int, string>
     */
    private function mergeAdjacentRewardClauses(array $clauses): array
    {
        $merged = [];
        foreach ($clauses as $clause) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0
                && str_ends_with($merged[$lastIndex], 'Gold獲得量を増やす')
                && str_starts_with($clause, '通常素材枠の抽選率を')
            ) {
                $merged[$lastIndex] = mb_substr(
                    $merged[$lastIndex],
                    0,
                    mb_strlen($merged[$lastIndex]) - mb_strlen('増やす'),
                ).'増やし、'.$clause;

                continue;
            }

            if ($lastIndex >= 0
                && preg_match('/Gold獲得量を\d+%増やす$/u', $merged[$lastIndex]) === 1
                && str_starts_with($clause, '通常素材枠の抽選率を')
            ) {
                $merged[$lastIndex] = (preg_replace('/増やす$/u', '増やし、'.$clause, $merged[$lastIndex]) ?? $merged[$lastIndex]);

                continue;
            }

            if ($lastIndex >= 0
                && preg_match('/^この攻撃の命中率を\+(\d+)ポイントする$/u', $merged[$lastIndex], $matches) === 1
                && preg_match('/^この攻撃の会心率を\+(\d+)ポイントする$/u', $clause, $criticalMatches) === 1
            ) {
                $merged[$lastIndex] = "この攻撃の命中率を+{$matches[1]}ポイントし、会心率を+{$criticalMatches[1]}ポイントする";

                continue;
            }

            $merged[] = $clause;
        }

        return $merged;
    }

    /**
     * @param  array<int, string>  $clauses
     * @return array<int, string>
     */
    private function mergeAdjacentRecoveryClauses(array $clauses): array
    {
        $merged = [];
        foreach ($clauses as $clause) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0
                && str_ends_with($merged[$lastIndex], '自分のHPを回復する')
                && str_starts_with($clause, '最大SPの')
                && str_ends_with($clause, 'を回復する')
            ) {
                $merged[$lastIndex] = (preg_replace(
                    '/自分のHPを回復する$/u',
                    '自分のHPを回復し、'.$clause,
                    $merged[$lastIndex],
                ) ?? $merged[$lastIndex]);

                continue;
            }

            $merged[] = $clause;
        }

        return $merged;
    }

    private function clarifyOneTurnDuration(string $clause): string
    {
        return str_starts_with($clause, '1ターンの間、')
            ? '次のターン終了まで、'.mb_substr($clause, mb_strlen('1ターンの間、'))
            : $clause;
    }

    /**
     * @param  array<int, string>  $clauses
     * @return array<int, string>
     */
    private function mergeAdjacentStatChangeClauses(array $clauses): array
    {
        $merged = [];
        $count = count($clauses);

        for ($index = 0; $index < $count; $index++) {
            $first = $this->simpleStatChangeClause($clauses[$index]);
            if ($first === null) {
                $merged[] = $clauses[$index];

                continue;
            }

            $changes = $first['changes'];
            $nextIndex = $index + 1;
            while ($nextIndex < $count) {
                $next = $this->simpleStatChangeClause($clauses[$nextIndex]);
                if ($next === null
                    || $next['targets_enemy'] !== $first['targets_enemy']
                    || $next['duration_turns'] !== $first['duration_turns']
                ) {
                    break;
                }

                $changes = [...$changes, ...$next['changes']];
                $nextIndex++;
            }

            if ($nextIndex === $index + 1) {
                $merged[] = $clauses[$index];

                continue;
            }

            $byValue = [];
            foreach ($changes as $change) {
                $byValue[$change['value']] ??= [];
                $byValue[$change['value']][] = $change['stat'];
            }

            $parts = [];
            foreach ($byValue as $value => $stats) {
                $statCopy = count($stats) === 2
                    ? implode('と', $stats)
                    : implode('・', $stats);
                $parts[] = "{$statCopy}を{$value}";
            }

            $durationPrefix = $first['duration_turns'] !== null
                ? $first['duration_turns'].'ターンの間、'
                : '';
            $merged[] = $durationPrefix.($first['targets_enemy'] ? '相手の' : '').implode('、', $parts).'する';
            $index = $nextIndex - 1;
        }

        return $merged;
    }

    /**
     * @return array{
     *     targets_enemy: bool,
     *     duration_turns: ?int,
     *     changes: array<int, array{stat: string, value: string}>
     * }|null
     */
    private function simpleStatChangeClause(string $clause): ?array
    {
        if (preg_match(
            '/^(?:(\d+)ターンの間、)?(相手の)?((?:攻撃|防御|魔力|精神|敏捷|運)(?:と(?:攻撃|防御|魔力|精神|敏捷|運))*)を([+\-]\d+%)する$/u',
            $clause,
            $matches,
        ) !== 1) {
            return null;
        }

        $changes = [];
        foreach (explode('と', $matches[3]) as $stat) {
            $changes[] = ['stat' => $stat, 'value' => $matches[4]];
        }

        return [
            'targets_enemy' => ($matches[2] ?? '') !== '',
            'duration_turns' => ($matches[1] ?? '') !== '' ? (int) $matches[1] : null,
            'changes' => $changes,
        ];
    }

    /** @return array<int, string> */
    private function memoEffectClauses(Skill $skill, string $effectTemplate): array
    {
        if ($this->isExactArt($skill, 8, 1, '金貨投げ')
            || $this->isExactArt($skill, 28, 5, '無拍子')
            || $this->isExactArt($skill, 29, 9, '極大魔法')
            || $this->isExactArt($skill, 33, 5, '羅刹連撃')
            || $this->isExactArt($skill, 37, 5, 'シャドウスナイプ')
            || $this->isExactArt($skill, 64, 5, '影冠狙撃')
            || $this->isExactArt($skill, 18, 9, '終弓・星穿ち')
        ) {
            return [];
        }

        $memo = $this->playerFacingText((string) ($skill->memo ?: $skill->description));
        if ($memo === '') {
            return [];
        }

        $clauses = [];
        foreach (preg_split('/[。＋]+/u', $memo) ?: [] as $clause) {
            $clause = $this->sentenceFragment($clause);
            if ($clause === '' || $this->isGenericMemoClause($skill, $clause, $effectTemplate)) {
                continue;
            }
            $clauses[] = $clause;
        }

        return array_values(array_unique($clauses));
    }

    private function isGenericMemoClause(Skill $skill, string $clause, string $effectTemplate): bool
    {
        if (preg_match('/^(?:攻撃|防御|魔力|精神|敏捷|運)(?:・(?:攻撃|防御|魔力|精神|敏捷|運))*参照$/u', $clause) === 1) {
            return true;
        }

        if (! JobArtEffectCatalog::dealsDamage($effectTemplate)) {
            return false;
        }

        if ($effectTemplate === 'MULTI_HIT'
            && preg_match('/^\d+回(?:の)?(?:物理)?攻撃$/u', $clause) === 1
        ) {
            return true;
        }

        if (in_array($effectTemplate, ['MAGICAL_DAMAGE', 'MAGICAL_DAMAGE_BUFF', 'MAGICAL_DAMAGE_REWARD'], true)
            && preg_match('/^(?:単体)?(?:小|中|大|特大)?(?:無属性)?魔法$/u', $clause) === 1
        ) {
            return true;
        }

        if ($effectTemplate === 'HYBRID_DAMAGE'
            && (preg_match('/^(?:敵|相手)?(?:単体)?に?(?:小|中|大|特大)?複合ダメージ(?:を与える)?$/u', $clause) === 1
                || preg_match('/^攻撃\s*(?:\/|\+)\s*魔力の(?:高い方|平均(?:値)?)を参照(?:する)?(?:単体攻撃)?$/u', $clause) === 1)
        ) {
            return true;
        }

        if ($effectTemplate === 'DRAIN'
            && (float) $skill->drain_hp_rate > 0
            && preg_match('/^(?:与えたダメージ|与ダメ).*(?:HP)?(?:回復|吸収)$/u', $clause) === 1
        ) {
            return true;
        }

        if ((int) $skill->self_damage_percent > 0
            && preg_match('/^(?:その後、)?反動.*最大HP.*(?:消費|ダメージ)$/u', $clause) === 1
        ) {
            return true;
        }

        if ((int) $skill->def_ignore_percent <= 0
            && preg_match('/(?:DEF|防御).*(?:無視|貫通)/u', $clause) === 1
        ) {
            return true;
        }

        return preg_match(
            '/^(?:敵|相手)?(?:単体)?に?(?:小|中|大|特大)?(?:聖属性)?(?:物理|魔力)?ダメージ(?:を与える)?$/u',
            $clause,
        ) === 1;
    }

    private function plainActionDescription(Skill $skill, string $effectTemplate, int $effectiveHitCount = 1): string
    {
        if ($effectTemplate === 'MULTI_HIT'
            && $this->isExactArt($skill, 37, 5, 'シャドウスナイプ')
        ) {
            return "自分の攻撃と相手の防御を参照し、相手に{$effectiveHitCount}回の物理ダメージを与える";
        }

        return match ($effectTemplate) {
            'PHYSICAL_DAMAGE', 'DAMAGE_DEBUFF',
            'PHYSICAL_DAMAGE_GOLD_REWARD', 'PHYSICAL_DAMAGE_REWARD' => $effectiveHitCount > 1
                ? "相手に{$effectiveHitCount}回の物理ダメージを与える"
                : '相手に物理ダメージを与える',
            'DAMAGE_GUARD_BARRIER' => $effectiveHitCount > 1
                ? "相手に{$effectiveHitCount}回の物理ダメージを与える"
                : '相手に物理ダメージを与える',
            'DAMAGE_BUFF' => $effectiveHitCount > 1
                ? "相手に自身の通常攻撃と同じ種類のダメージを{$effectiveHitCount}回与える"
                : '相手に自身の通常攻撃と同じ種類のダメージを与える',
            'MAGICAL_DAMAGE', 'MAGICAL_DAMAGE_BUFF', 'MAGICAL_DAMAGE_REWARD' => $effectiveHitCount > 1
                ? "相手に{$effectiveHitCount}回の魔力ダメージを与える"
                : '相手に魔力ダメージを与える',
            'HYBRID_DAMAGE' => $this->hybridActionDescription($skill, effectiveHitCount: $effectiveHitCount),
            'MULTI_HIT' => $effectiveHitCount > 1
                ? "相手に{$effectiveHitCount}回の物理ダメージを与える"
                : '相手に物理ダメージを与える',
            'SELF_BUFF' => '自分を強化する',
            'ENEMY_DEBUFF' => '相手を弱体化する',
            'GUARD_BARRIER' => '',
            'HEAL' => '自分のHPを回復する',
            'HEAL_CLEANSE' => '自分のHPを回復し、有害状態を浄化する',
            'DRAIN' => $this->drainActionDescription($skill),
            'GUTS' => '戦闘中、致死ダメージを1回だけHP1で耐える効果を得る（効果は重複しない）',
            'REWARD_GOLD' => '勝利時のGold獲得量を増やす',
            'REWARD_DROP' => '勝利時のドロップ獲得率を増やす',
            'REWARD_MIXED' => '勝利時のGoldとドロップ獲得を増やす',
            'TIME_CONTROL_CURRENT_ONLY' => '戦闘の時間へ干渉する',
            'V2_ROLE_EFFECT_ONLY' => '固有効果を発動する',
            default => '戦況に応じた効果を発動する',
        };
    }

    private function isExactArt(Skill $skill, int $jobId, int $rank, string $name): bool
    {
        return $skill->isJobArt()
            && (int) $skill->job_id === $jobId
            && (int) $skill->learn_rank === $rank
            && (string) $skill->name === $name;
    }

    /** @param array<int, string> $texts */
    private function playerFacingTexts(array $texts): array
    {
        $normalized = [];
        foreach ($texts as $text) {
            $text = $this->playerFacingText($text);
            if ($text !== '' && ! in_array($text, $normalized, true)) {
                $normalized[] = $text;
            }
        }

        return $normalized;
    }

    private function playerFacingText(string $text): string
    {
        $text = strtr($text, [
            'Rank1' => '始動',
            'Rank5' => '連携',
            'Rank9' => '奥義',
            'maxHP' => '最大HP',
            'maxSP' => '最大SP',
            'ATK' => '攻撃',
            'DEF' => '防御',
            'MAG' => '魔力',
            'SPR' => '精神',
            'SPD' => '敏捷',
            'LUK' => '運',
            'ラウンド' => 'ターン',
            '直接ダメージ' => '直接攻撃のダメージ',
            'direct damage' => '直接攻撃のダメージ',
            'resource' => '系譜リソース',
            'master' => '基礎',
            'counter_focus' => '剣気集中の準備効果',
            '追加自己強化なし' => '追加の自己強化はない',
            '既存の各Hit会心判定を維持' => '各Hitで会心判定を行う',
            '魔法ダメージ' => '魔力ダメージ',
        ]);
        $text = (string) preg_replace('/[（(]?1戦\s*\d+\s*回(?:まで)?[）)]?/u', '', $text);
        $text = (string) preg_replace('/(?:内部)?CT\s*\d+(?:ターン)?/u', '', $text);
        $text = (string) preg_replace('/\s{2,}/u', ' ', $text);
        $text = (string) preg_replace('/[、・\/\s]+$/u', '', trim($text));

        return trim($text);
    }

    private function effectDetailSentence(string $text): string
    {
        $text = $this->playerFacingText($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^HP回復\s+精神×(\d+)%$/u', $text, $matches) === 1) {
            return "精神の{$matches[1]}%分、自分のHPを回復する";
        }
        if (preg_match('/^最大HP回復\s+\+(\d+)%$/u', $text, $matches) === 1) {
            return "最大HPの{$matches[1]}%を回復する";
        }
        if (preg_match('/^最大SP回復\s+\+(\d+)%$/u', $text, $matches) === 1) {
            return "最大SPの{$matches[1]}%を回復する";
        }
        if (preg_match('/^最大(HP|SP)の(\d+)%回復$/u', $text, $matches) === 1) {
            return "最大{$matches[1]}の{$matches[2]}%を回復する";
        }
        if (preg_match('/^反動：最大HP\s*-([0-9.]+)%$/u', $text, $matches) === 1) {
            return "反動で最大HPの{$matches[1]}%分のダメージを受ける";
        }
        if (preg_match('/^自己強化 主\+(\d+)% \/ 副\+(\d+)%(?:（(\d+)ターン）)?$/u', $text, $matches) === 1) {
            $copy = "自分の通常攻撃が物理なら攻撃を+{$matches[1]}%、防御を+{$matches[2]}%、魔法なら魔力を+{$matches[1]}%、精神を+{$matches[2]}%する";

            return ($matches[3] ?? '') !== ''
                ? "{$matches[3]}ターンの間、{$copy}"
                : $copy;
        }
        if (preg_match('/^Gold判定\s+\+(\d+)%$/u', $text, $matches) === 1) {
            return "通常探索勝利時のGold獲得量を{$matches[1]}%増やす";
        }
        if (preg_match('/^素材判定\s+\+(\d+)%$/u', $text, $matches) === 1) {
            return "通常素材枠の抽選率を{$matches[1]}ポイント上げる";
        }
        if (preg_match('/^レア判定\s+\+(\d+)%$/u', $text, $matches) === 1) {
            return "レア装備の獲得率を{$matches[1]}%加算する";
        }
        if (preg_match('/^直前の自分の行動後に物理攻撃を受けていれば最終ダメージ\s*×([0-9.]+)$/u', $text, $matches) === 1) {
            return "直前の自分の行動後に物理攻撃を受けていた場合、与えるダメージを{$matches[1]}倍にする";
        }
        if (str_ends_with($text, '延長')) {
            return $text.'する';
        }
        if (preg_match('/軽減（\d+回）$/u', $text) === 1) {
            return str_replace('軽減（', '軽減する（', $text);
        }

        if (preg_match('/^(?:発動後\s*)?(.+?)（(\d+)ターン(?:・([^）]+))?）$/u', $text, $matches) === 1) {
            $statCopy = $this->statChangeSentence((string) $matches[1]);
            if ($statCopy !== null) {
                $copy = "{$matches[2]}ターンの間、{$statCopy}する";
                if (($matches[3] ?? '') !== '') {
                    $copy .= '（'.$matches[3].'）';
                }

                return $copy;
            }
        }

        $statCopy = $this->statChangeSentence($text);
        if ($statCopy !== null) {
            return $statCopy.'する';
        }

        return $this->sentenceFragment($text);
    }

    /**
     * @param  array<int, string>  $effects
     * @return array<int, string>
     */
    private function withMasterDuration(Skill $skill, array $effects): array
    {
        $duration = max(0, (int) $skill->duration_turns);
        if ($duration === 0) {
            return $effects;
        }

        return array_map(static function (string $effect) use ($duration): string {
            if (str_contains($effect, '（') || str_contains($effect, '(')) {
                return $effect;
            }
            if (str_starts_with($effect, '敵')) {
                return "{$effect}（{$duration}ターン）";
            }

            return $effect;
        }, $effects);
    }

    private function sentenceFragment(string $text): string
    {
        $text = trim($text);

        return preg_replace('/[。！!]+$/u', '', $text) ?? $text;
    }

    private function statChangeSentence(string $text): ?string
    {
        $text = trim($text);
        $targetsEnemy = str_starts_with($text, '敵');
        if ($targetsEnemy) {
            $text = mb_substr($text, 1);
        }

        if (preg_match(
            '/^((?:攻撃|防御|魔力|精神|敏捷|運)(?:\s*\/\s*(?:攻撃|防御|魔力|精神|敏捷|運))+?)\s*([+\-]\d+%)$/u',
            $text,
            $matches,
        ) === 1) {
            $stats = preg_split('/\s*\/\s*/u', $matches[1]) ?: [];
            $subject = ($targetsEnemy ? '相手の' : '').implode('と', $stats);

            return $subject.'を'.$matches[2];
        }

        $text = preg_replace_callback(
            '/(攻撃|防御|魔力|精神|敏捷|運)\s*\/\s*(攻撃|防御|魔力|精神|敏捷|運)\s*([+\-]\d+%)/u',
            static fn (array $matches): string => "{$matches[1]}と{$matches[2]} {$matches[3]}",
            $text,
        ) ?? $text;
        $parts = preg_split('/\s*\/\s*/u', $text) ?: [];
        $changes = [];
        foreach ($parts as $part) {
            if (preg_match('/^((?:攻撃|防御|魔力|精神|敏捷|運)(?:と(?:攻撃|防御|魔力|精神|敏捷|運))?)\s*([+\-]\d+%)$/u', trim($part), $matches) !== 1) {
                return null;
            }
            $changes[] = ['stats' => $matches[1], 'value' => $matches[2]];
        }

        if ($changes === []) {
            return null;
        }
        if (count($changes) === 2 && $changes[0]['value'] === $changes[1]['value']) {
            return ($targetsEnemy ? '相手の' : '').$changes[0]['stats'].'と'.$changes[1]['stats'].'を'.$changes[0]['value'];
        }

        $copy = implode('、', array_map(
            static fn (array $change): string => $change['stats'].'を'.$change['value'],
            $changes,
        ));

        return ($targetsEnemy ? '相手の' : '').$copy;
    }

    private function actionDescription(Skill $skill, string $effectTemplate): string
    {
        return match ($effectTemplate) {
            'PHYSICAL_DAMAGE' => '相手に物理ダメージを与えます',
            'MAGICAL_DAMAGE' => '相手に魔力ダメージを与えます',
            'HYBRID_DAMAGE' => $this->hybridActionDescription($skill, polite: true),
            'MULTI_HIT' => '相手に複数回の物理ダメージを与えます',
            'DAMAGE_BUFF' => '相手に自身の通常攻撃と同じ種類のダメージを与え、自分を強化します',
            'MAGICAL_DAMAGE_BUFF' => '相手に魔力ダメージを与え、自分を強化します',
            'DAMAGE_DEBUFF' => '相手に物理ダメージを与え、相手を弱体化します',
            'DAMAGE_GUARD_BARRIER' => '相手に物理ダメージを与え、次の自分の行動開始まで受けるダメージを軽減します',
            'SELF_BUFF' => '自分を強化します',
            'ENEMY_DEBUFF' => '相手を弱体化します',
            'GUARD_BARRIER' => '次の自分の行動開始まで受けるダメージを軽減します',
            'HEAL' => 'HPを回復します',
            'HEAL_CLEANSE' => 'HPを回復し、有害状態を浄化します',
            'DRAIN' => $this->drainActionDescription($skill, polite: true),
            'GUTS' => '戦闘中、致死ダメージを1回だけHP1で耐える効果を得ます（効果は重複しません）',
            'REWARD_GOLD' => '勝利時のGold獲得を増やします',
            'REWARD_DROP' => '勝利時のドロップ獲得を増やします',
            'REWARD_MIXED' => '勝利時のGoldとドロップ獲得を増やします',
            'PHYSICAL_DAMAGE_GOLD_REWARD' => '相手に物理ダメージを与え、勝利時のGold獲得を増やします',
            'PHYSICAL_DAMAGE_REWARD' => '相手に物理ダメージを与え、勝利時の報酬獲得を増やします',
            'MAGICAL_DAMAGE_REWARD' => '相手に魔力ダメージを与え、勝利時の報酬獲得を増やします',
            'TIME_CONTROL_CURRENT_ONLY' => '戦闘の時間へ干渉します',
            'V2_ROLE_EFFECT_ONLY' => '固有効果を発動します',
            default => '戦況に応じた効果を発動します',
        };
    }

    private function hybridActionDescription(
        Skill $skill,
        bool $polite = false,
        int $effectiveHitCount = 1,
    ): string
    {
        $scaling = strtolower(trim((string) $skill->hybrid_scaling));
        $reference = $scaling === 'max'
            ? '自分の攻撃と魔力の高い方'
            : '自分の攻撃と魔力の平均値';
        $damageCopy = $effectiveHitCount > 1
            ? "{$effectiveHitCount}回のダメージ"
            : 'ダメージ';

        return "{$reference}を基準に、相手の防御を参照して{$damageCopy}を与え".($polite ? 'ます' : 'る');
    }

    private function drainActionDescription(Skill $skill, bool $polite = false): string
    {
        $damage = JobArtEffectCatalog::drainDamageType($skill->damage_type) === 'physical'
            ? '物理ダメージ'
            : '魔力ダメージ';
        $ending = $polite ? 'ます' : 'る';

        if ((float) $skill->drain_hp_rate <= 0) {
            return "相手に{$damage}を与え{$ending}";
        }

        $drainPercent = $this->compactNumber((float) $skill->drain_hp_rate * 100);
        $recoveryTarget = $polite ? 'HP' : '自分のHP';
        $recoveryEnding = $polite ? 'します' : 'する';

        return "相手に{$damage}を与え、与えたダメージの{$drainPercent}%分、{$recoveryTarget}を回復{$recoveryEnding}";
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>  $effectTexts
     * @return array<int, string>
     */
    private function roleEffectTextsForDisplay(
        Skill $skill,
        ?array $metadata,
        array $effectTexts,
        int $effectivePower,
        string $originKey,
    ): array {
        $heal = is_array($metadata['heal'] ?? null) ? $metadata['heal'] : null;
        if (($heal['formula'] ?? null) !== 'existing_spr') {
            return $effectTexts;
        }

        $executionPower = max(0, (int) ($effectivePower ?: 100));
        $healPercent = $executionPower * max(0.0, (float) ($heal['multiplier'] ?? 1.0));
        $healCopy = '精神の'.$this->compactNumber($healPercent).'%分、自分のHPを回復する';

        foreach ($effectTexts as $index => $effectText) {
            if (str_contains($effectText, '基礎回復量')
                || preg_match('/^HP回復\s+(?:SPR|精神)×\d+(?:\.\d+)?%$/u', $effectText) === 1
            ) {
                $effectTexts[$index] = $healCopy;
            }
        }

        return array_values(array_unique($effectTexts));
    }

    private function compactNumber(float $value): string
    {
        $formatted = number_format(round($value, 2), 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function adaptiveRouteActionDescription(bool $polite = false): string
    {
        return '攻撃と相手の防御で計算する物理経路と、魔力と相手の精神で計算する魔法経路を比較し、'
            .'期待ダメージが高い方で1回攻撃'.($polite ? 'します' : 'する');
    }

    private function isRewardOnlyTemplate(string $effectTemplate): bool
    {
        return in_array($effectTemplate, ['REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED'], true);
    }

    /** @param array<int, string> $effectDetails */
    private function hasConcreteRewardDetail(array $effectDetails): bool
    {
        foreach ($effectDetails as $effectDetail) {
            if (str_contains($effectDetail, 'Gold獲得量')
                || str_contains($effectDetail, '通常素材枠の抽選率')
                || str_contains($effectDetail, 'レア装備の獲得率')
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $effectDetails */
    private function hasConcreteHealDetail(array $effectDetails): bool
    {
        foreach ($effectDetails as $effectDetail) {
            if (str_contains($effectDetail, 'HPを回復する')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $effectDetails */
    private function hasConcreteStatChangeDetail(array $effectDetails): bool
    {
        foreach ($effectDetails as $effectDetail) {
            if (preg_match('/(?:相手の)?(?:攻撃|防御|魔力|精神|敏捷|運).*[+\-]\d+%/u', $effectDetail) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $numericEffects
     * @param  array<string, mixed>|null  $roleEffectMetadata
     * @return array<int, string>
     */
    private function preservedNumericEffects(array $numericEffects, ?array $roleEffectMetadata): array
    {
        $reward = $roleEffectMetadata['reward'] ?? null;
        if (! is_array($reward)) {
            return [];
        }

        $preservesGold = ($reward['gold'] ?? null) === 'preserve_master';
        $preservesDrop = ($reward['drop'] ?? null) === 'preserve_master';

        return array_values(array_filter(
            $numericEffects,
            static function (string $effect) use ($preservesGold, $preservesDrop): bool {
                if ($preservesGold && str_starts_with($effect, 'Gold判定 ')) {
                    return true;
                }

                return $preservesDrop
                    && (str_starts_with($effect, '素材判定 ') || str_starts_with($effect, 'レア判定 '));
            },
        ));
    }

    private function asSentence(string $copy): string
    {
        return str_ends_with($copy, '。') ? $copy : $copy.'。';
    }

    /**
     * @param  array<int, int>  $ranks
     * @param  array<int, Skill>  $trustedArtsByRank
     * @return array<int, array{role_label: string, art_name: ?string, conditional_priority: bool}>
     */
    private function prioritySteps(array $ranks, array $trustedArtsByRank): array
    {
        return array_map(function (int $rank) use ($trustedArtsByRank): array {
            return [
                'role_label' => match ($rank) {
                    1 => '始動',
                    5 => '連携',
                    9 => '奥義',
                },
                'art_name' => $this->artName($trustedArtsByRank, $rank),
                'conditional_priority' => $rank === 9,
            ];
        }, $ranks);
    }

    /**
     * @param  array<int, Skill>  $trustedArtsByRank
     * @return array<int, array{role_label: string, art_name: ?string, conditional_priority: bool}>
     */
    private function commandPrioritySteps(string $style, array $trustedArtsByRank): array
    {
        if ($style === 'cycle') {
            return [
                ['role_label' => '連携', 'art_name' => $this->artName($trustedArtsByRank, 5), 'conditional_priority' => false],
                ['role_label' => '通常攻撃／現在職技', 'art_name' => null, 'conditional_priority' => false],
                ['role_label' => '奥義', 'art_name' => $this->artName($trustedArtsByRank, 9), 'conditional_priority' => true],
            ];
        }

        return [
            ['role_label' => '通常攻撃／現在職技', 'art_name' => null, 'conditional_priority' => false],
            ['role_label' => '奥義', 'art_name' => $this->artName($trustedArtsByRank, 9), 'conditional_priority' => true],
        ];
    }

    /** @param array<int, Skill> $trustedArtsByRank */
    private function artName(array $trustedArtsByRank, int $rank): ?string
    {
        $art = $trustedArtsByRank[$rank] ?? null;
        $name = trim((string) ($art?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function minimumResourcePoints(?int $currentJobId, int $rank, int $fallback): int
    {
        $metadata = $this->prototypeCatalog->artResourceMetadataForJobRank($currentJobId, $rank);

        return max(0, (int) ($metadata['minimum_resource_points'] ?? $fallback));
    }

    /**
     * @param  array<int, Skill>  $trustedArtsByRank
     */
    private function jobSpecificRecommendation(
        ?int $currentJobId,
        string $style,
        string $resourceName,
        array $trustedArtsByRank,
        int $consumerMinimum,
        int $finisherMinimum,
    ): ?string {
        $rankFiveName = $this->artName($trustedArtsByRank, 5) ?? '連携戦技';
        $rankNineName = $this->artName($trustedArtsByRank, 9) ?? '奥義';

        if ($currentJobId === 62) {
            $rankFiveRate = $this->penetrationRatePercent(62, 5);
            $rankNineRate = $this->penetrationRatePercent(62, 9);

            return $style === 'finisher'
                ? "貫通構えを整え、{$resourceName}を連携戦技に使わず{$finisherMinimum}ptまで温存し、{$rankNineName}の物理防御{$rankNineRate}%貫通を狙います。"
                : "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、構え時に物理防御{$rankFiveRate}%貫通を繰り返します。";
        }

        if ($currentJobId === 60) {
            return $style === 'finisher'
                ? "構え・通常攻撃・被物理攻撃で{$resourceName}を蓄積し、{$finisherMinimum}ptを温存して{$rankNineName}を狙います。"
                : "受け流しや被物理攻撃で{$resourceName}を回収し、{$consumerMinimum}ptごとに{$rankFiveName}を使います。";
        }

        if ($currentJobId === 66) {
            return $style === 'finisher'
                ? "加護・実軽減・通常攻撃で{$resourceName}を蓄積し、{$finisherMinimum}ptを温存して{$rankNineName}を狙います。"
                : "{$rankFiveName}の浄化と20%加護を繰り返し、{$resourceName}を防御へ循環させます。";
        }

        if ($currentJobId === 69) {
            return $style === 'finisher'
                ? "通常攻撃や現在職技で{$resourceName}を貯め、{$finisherMinimum}ptを温存して{$rankNineName}を狙います。"
                : "通常攻撃や現在職技で{$resourceName}を補充し、{$consumerMinimum}ptごとに{$rankFiveName}を使います。";
        }

        if ($currentJobId === 68) {
            return $style === 'finisher'
                ? "始動HITや通常攻撃で{$resourceName}を蓄積し、{$finisherMinimum}ptを温存して長時間の崩しを伴う{$rankNineName}を狙います。"
                : "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、防御・精神低下を維持しながら戦います。";
        }

        if ($currentJobId === 67) {
            return $style === 'finisher'
                ? "始動HITや通常攻撃で{$resourceName}を蓄積し、金蝕で相手の系譜リソース獲得を抑えながら{$rankNineName}を狙います。"
                : "金蝕で相手の系譜リソース獲得を抑え、獲得がなかった時は{$rankFiveName}で一部補償を受ける戦型です。";
        }

        return match ([$currentJobId, $style]) {
            [53, 'cycle'] => "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、星光の場を延長しながら戦います。",
            [85, 'cycle'] => "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、現在の場を2ターン固定しながら戦います。",
            [85, 'finisher'] => "{$resourceName}を連携戦技に使わず{$finisherMinimum}ptまで温存し、{$rankNineName}と旋律の副場を狙います。",
            default => $style === 'finisher'
                ? "{$resourceName}を連携戦技に使わず{$finisherMinimum}ptまで温存し、{$rankNineName}を狙います。"
                : "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、連携戦技を繰り返します。",
        };
    }

    private function penetrationRatePercent(int $jobId, int $rank): int
    {
        $metadata = $this->prototypeCatalog->artResourceMetadataForJobRank($jobId, $rank);

        return (int) round(max(0.0, (float) ($metadata['penetration_rate'] ?? 0)) * 100);
    }

    /** @param array<string, int|float|string|bool> $metadata */
    private function resourceText(ResourceRole $role, array $metadata): ?string
    {
        $resourceName = (string) $metadata['resource_name'];
        $gain = max(0, (int) ($metadata['resource_gain_points'] ?? 0));
        $cost = max(0, (int) ($metadata['resource_cost_points'] ?? 0));
        $minimum = max(0, (int) ($metadata['minimum_resource_points'] ?? $cost));
        $gainTiming = match ((string) ($metadata['resource_gain_event'] ?? ResourceEvent::JOB_ART_CAST->value)) {
            ResourceEvent::JOB_ART_HIT->value => 'HIT時',
            ResourceEvent::HP_SP_CONVERSION_SUCCESS->value => '変換成功時',
            default => '使用時',
        };

        return match ($role) {
            ResourceRole::PRODUCER => $gain > 0 ? "{$resourceName} +{$gain}（{$gainTiming}）" : null,
            ResourceRole::CONSUMER, ResourceRole::FINISHER => $cost > 0
                ? "{$resourceName} -{$cost}（消費".($minimum > $cost ? "・{$minimum}以上で使用" : '').'）'
                : null,
            ResourceRole::NEUTRAL => null,
        };
    }

    /**
     * @param  array<string, int|float|string|bool>  $metadata
     * @return array<int, string>
     */
    private function effectTexts(?int $currentJobId, Skill $skill, array $metadata): array
    {
        $rank = (int) $skill->learn_rank;

        if ($currentJobId === 65) {
            if ($rank === 1) {
                return [];
            }

            $accuracy = max(0, (int) ($metadata['accuracy_delta_points'] ?? 0));
            $pressure = (int) round(max(0.0, (float) ($metadata['sp_pressure_rate'] ?? 0.0)) * 100);

            return $accuracy > 0 && $pressure > 0
                ? ["この攻撃の命中率を+{$accuracy}ポイントする", "HITした場合、相手の最大SPの{$pressure}%分、現在SPを減らす"]
                : [];
        }

        if ($currentJobId === 60 && $rank === 1) {
            return [];
        }

        if ($currentJobId === 66) {
            $reduction = (int) round(max(0.0, (float) ($metadata['guard_rate'] ?? 0.0)) * 100);
            if ($rank === 1) {
                return ["次に受ける直接ダメージを{$reduction}%軽減する"];
            }
            if ($rank === 5) {
                return ['火傷・毒・出血・防御低下・鈍足・回復阻害・崩し印をすべて浄化する', "次に受ける直接ダメージを{$reduction}%軽減する"];
            }
            if ($rank === 9) {
                return ["次に受ける直接ダメージを{$reduction}%軽減する"];
            }
        }

        if ($currentJobId === 69 && $rank === 1) {
            return [];
        }

        if ($currentJobId === 67 && $rank === 1) {
            return ['HIT時に対象へ金蝕1回（次の系譜リソース獲得行動で各獲得量-1、最低1）'];
        }

        if ($currentJobId === 68) {
            if ($rank === 1) {
                return ['HIT時：対象へ崩し印+1'];
            }

            return ['浄化された冠位由来の崩し印を残心として1戦1回保持', '残心があれば次のHITで崩し印へ再接続'];
        }

        return [];
    }

    /**
     * @param  array<string, int|float|string|bool>  $metadata
     * @return array<int, string>
     */
    private function fieldTexts(
        ?int $currentJobId,
        Skill $skill,
        array $metadata,
        bool $formalCDesignLineage = false,
    ): array
    {
        if (! $this->featureGate->usesFieldsForCurrentJob($currentJobId)
            || $this->fieldCatalog->forArt($skill) === null
        ) {
            return [];
        }

        $operation = (string) ($metadata['field_operation'] ?? 'none');
        $fieldKey = (string) ($metadata['field_key'] ?? '');

        if ((int) $skill->job_id === 63) {
            return match ((int) $skill->learn_rank) {
                1 => ['5種類の場を固定順で次へ張り替え', '実際の場上書き時：星印+2（基礎+4と合計）'],
                5 => ['直前に上書きされた自分の場を1ラウンド残響として保持'],
                9 => [sprintf(
                    'この奥義の行動開始時に自分の主場がある場合、この戦闘中に自分が場を上書きした回数が1～2回なら威力を%d%%、3～4回なら%d%%、5回以上なら%d%%上げる',
                    (int) round(((float) ($metadata['field_overwrite_power_multiplier_1_2'] ?? 1.0) - 1.0) * 100),
                    (int) round(((float) ($metadata['field_overwrite_power_multiplier_3_4'] ?? 1.0) - 1.0) * 100),
                    (int) round(((float) ($metadata['field_overwrite_power_multiplier_5_plus'] ?? 1.0) - 1.0) * 100),
                )],
                default => [],
            };
        }

        return match ($operation) {
            'deploy' => $fieldKey !== ''
                ? [$this->fieldCatalog->name($fieldKey).'を'.JobArtV2FieldService::BASE_DURATION.'ラウンド展開する']
                : [],
            'extend' => ['現在の場を'.max(1, (int) ($metadata['field_extend_rounds'] ?? 1)).'ラウンド延長する（延長後の残りラウンドは最大8ラウンド）'],
            'lock' => ['現在の場を'.max(1, (int) ($metadata['field_lock_rounds'] ?? 1)).'ラウンド固定'],
            'overlay' => $fieldKey !== ''
                ? [str_replace('の場', 'の副場', $this->fieldCatalog->name($fieldKey)).'を1ラウンド生成']
                : [],
            default => [],
        };
    }

    /**
     * @param  array<string, int|float|string|bool>  $metadata
     * @return array<int, string>
     */
    private function stanceTexts(
        ?int $currentJobId,
        Skill $skill,
        array $metadata,
        bool $formalCDesignLineage = false,
    ): array
    {
        if ($currentJobId === JobArtV2DefenseService::COUNTER_JOB_ID
            && (int) $skill->learn_rank === 1
            && isset($metadata['counter_stance_rounds'], $metadata['parry_rate'])
        ) {
            $rounds = max(1, (int) $metadata['counter_stance_rounds']);
            $rate = (int) round(max(0.0, (float) $metadata['parry_rate']) * 100);

            return ["剣冠の構え（{$rounds}ラウンド・直接物理攻撃を{$rate}%で受け流し）"];
        }

        if (! $this->featureGate->usesPenetrationStanceForCurrentJob($currentJobId)) {
            if ($this->featureGate->usesPenetrationForCurrentJob($currentJobId)
                && ($formalCDesignLineage
                    || $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill))
            ) {
                $rank = (int) $skill->learn_rank;
                $rate = (int) round(max(0.0, (float) ($metadata['penetration_rate'] ?? 0)) * 100);
                if (in_array($rank, [5, 9], true) && $rate > 0) {
                    return ["相手の物理防御を{$rate}%無視する"];
                }
            }

            return [];
        }

        $rank = (int) $skill->learn_rank;
        if ($rank === 1) {
            return ['貫通構えを取る'];
        }

        $rate = (int) round(max(0.0, (float) ($metadata['penetration_rate'] ?? 0)) * 100);
        if ($rank === 5 && $rate > 0) {
            return ["構え時：物理防御 {$rate}%貫通", '使用後、構えを再形成'];
        }
        if ($rank === 9 && $rate > 0) {
            return ["構え時：物理防御 {$rate}%貫通"];
        }

        return [];
    }
}
