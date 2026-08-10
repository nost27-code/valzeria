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
    ) {}

    public function enabledForCurrentJob(?int $currentJobId): bool
    {
        return $this->featureGate->usesLoadoutUiForCurrentJob($currentJobId);
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
                'traits' => ['奥義重視', 'Rank9を狙いやすい', '展開戦技は温存されやすい'],
                'steps' => $currentJobId === 69
                    ? $this->commandPrioritySteps('finisher', $trustedArtsByRank)
                    : $this->prioritySteps([1, 5, 9], $trustedArtsByRank),
                'priority_note' => $currentJobId === 69
                    ? "戦技を使わない手番を作り、通常攻撃や現在職技で{$resourceName}を貯めます。{$resourceName}{$finisherMinimum}ptなど条件成立時は奥義が優先されます。"
                    : "始動が使用可能な間は展開より先に判定されるため、{$resourceName}を温存して奥義を狙います。{$resourceName}{$finisherMinimum}ptなど条件成立時は奥義が優先されます。",
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
                'catch' => '展開戦技を繰り返す',
                'description' => $currentJobId === 69
                    ? '通常攻撃や現在職技で指揮点を補充し、4ptごとにRank5を繰り返して戦う戦型です。'
                    : 'リソースを展開戦技へ積極的に使い、Rank5を繰り返して戦う戦型です。',
                'suited_for' => '中～長期戦',
                'ultimate_outlook' => '狙いにくい',
                'traits' => ['展開重視', '継続火力', 'Rank9は狙いにくい'],
                'steps' => $currentJobId === 69
                    ? $this->commandPrioritySteps('cycle', $trustedArtsByRank)
                    : $this->prioritySteps([5, 1, 9], $trustedArtsByRank),
                'priority_note' => $currentJobId === 69
                    ? "{$resourceName}{$consumerMinimum}pt以上なら展開を先に使用し、不足時は通常攻撃や現在職技の手番で補充します。"
                    : "{$resourceName}{$consumerMinimum}pt以上ある時は展開を先に使用し、不足時は始動で補充します。",
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
                    ['role_label' => '展開／奥義', 'art_name' => null, 'conditional_priority' => true],
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
     *     damage_category: ?string,
     *     effect_template: string,
     *     effect_label: string,
     *     legacy_effect_copy_suppressed: bool
     * }|null
     */
    public function forArt(?int $currentJobId, Skill $skill): ?array
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
        $primaryResourceMetadata = $resourcesEnabled
            ? $this->resourceCatalog->forCurrentJobArt($currentJobId, $skill, $normalizedOrigin)
            : null;
        $isSameLineageInherited = $originKey === 'inherited' && $primaryResourceMetadata !== null;
        $isPortableFieldOrigin = $originKey === 'inherited'
            && $this->fieldCatalog->isPortableFieldArt($currentJobId, $skill);
        $sourceLineage = $this->lineageCatalog->forArt($skill);
        $sourceLineageName = $sourceLineage['lineage_name'] ?? null;
        $role = $this->roleForDisplay($skill, $primaryResourceMetadata ?? $metadata);
        $displayEffectTemplate = $this->effectSemanticsResolver->replacementEffectTemplateForDisplay($currentJobId, $skill)
            ?? (string) $skill->effect_template;
        $legacyEffectCopySuppressed = $displayEffectTemplate !== (string) $skill->effect_template;
        $effectTexts = $resourcesEnabled && $isTrustedCurrentOrigin
            ? $this->effectTexts($currentJobId, $skill, $metadata ?? [])
            : [];
        if ($resourcesEnabled) {
            $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
            if ($roleCatalog->isPortable($skill)) {
                $effectTexts = array_values(array_unique([
                    ...$effectTexts,
                    ...$roleCatalog->effectTexts($skill),
                ]));
            }
        }

        return [
            'role_key' => $role->value,
            'role_label' => $this->roleLabel($role),
            'origin_key' => $originKey,
            'origin_label' => $originLabel,
            'source_lineage_key' => $sourceLineage['lineage_key'] ?? null,
            'source_lineage_name' => $sourceLineageName,
            'lineage_relation' => $isTrustedCurrentOrigin
                ? 'current'
                : ($isSameLineageInherited ? 'same_lineage' : 'cross_lineage'),
            'source_badge' => match (true) {
                $isTrustedCurrentOrigin && $sourceLineageName !== null => "現在職・{$sourceLineageName}",
                $isSameLineageInherited => '継承・同系譜',
                $sourceLineageName !== null => "継承・{$sourceLineageName}",
                default => $originLabel,
            },
            'resource_text' => $primaryResourceMetadata !== null
                ? $this->resourceText($role, $primaryResourceMetadata)
                : null,
            'effect_texts' => $effectTexts,
            'field_texts' => ($isTrustedCurrentOrigin || $isPortableFieldOrigin) && $metadata !== null
                ? $this->fieldTexts($currentJobId, $skill, $metadata)
                : [],
            'stance_texts' => $isTrustedCurrentOrigin
                ? $this->stanceTexts($currentJobId, $skill, $metadata ?? [])
                : [],
            'priority_text' => $resourcesEnabled && $role === ResourceRole::FINISHER
                ? ($isTrustedCurrentOrigin
                    ? '条件成立時は最優先候補'
                    : ($isSameLineageInherited ? '現在職奥義が使用不能なら優先候補' : null))
                : null,
            'is_ultimate' => $role === ResourceRole::FINISHER,
            'effective_power' => $this->powerResolver->forDisplay($currentJobId, $skill),
            'damage_category' => $this->damageSemanticsResolver->forDisplay($currentJobId, $skill)['damage_category'] ?? null,
            'effect_template' => $displayEffectTemplate,
            'effect_label' => JobArtEffectCatalog::label($displayEffectTemplate) ?? $skill->jobArtEffectLabel(),
            'legacy_effect_copy_suppressed' => $legacyEffectCopySuppressed,
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
            ResourceRole::CONSUMER => '展開',
            ResourceRole::NEUTRAL => '対策',
            ResourceRole::FINISHER => '奥義',
        };
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
                    5 => '展開',
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
                ['role_label' => '展開', 'art_name' => $this->artName($trustedArtsByRank, 5), 'conditional_priority' => false],
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
        $rankFiveName = $this->artName($trustedArtsByRank, 5) ?? 'Rank5展開戦技';
        $rankNineName = $this->artName($trustedArtsByRank, 9) ?? 'Rank9奥義';

        if ($currentJobId === 62) {
            $rankFiveRate = $this->penetrationRatePercent(62, 5);
            $rankNineRate = $this->penetrationRatePercent(62, 9);

            return $style === 'finisher'
                ? "貫通構えを整え、{$resourceName}を展開戦技に使わず{$finisherMinimum}ptまで温存し、{$rankNineName}の{$rankNineRate}%DEF貫通を狙います。"
                : "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、構え時{$rankFiveRate}%DEF貫通を繰り返します。";
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
                : "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、DEF/SPR低下を維持しながら戦います。";
        }

        if ($currentJobId === 67) {
            return $style === 'finisher'
                ? "HPをSPへ変換して{$resourceName}を作り、{$finisherMinimum}ptを温存して{$rankNineName}を狙います。"
                : "変換と通常攻撃で{$resourceName}を補充し、{$consumerMinimum}ptごとに{$rankFiveName}を使います。";
        }

        return match ([$currentJobId, $style]) {
            [53, 'cycle'] => "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、星光の場を延長しながら戦います。",
            [85, 'cycle'] => "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、現在の場を2ラウンド固定しながら戦います。",
            [85, 'finisher'] => "{$resourceName}を展開戦技に使わず{$finisherMinimum}ptまで温存し、{$rankNineName}と旋律の副場を狙います。",
            default => $style === 'finisher'
                ? "{$resourceName}を展開戦技に使わず{$finisherMinimum}ptまで温存し、{$rankNineName}を狙います。"
                : "{$resourceName}{$consumerMinimum}ptごとに{$rankFiveName}を使い、展開戦技を繰り返します。",
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

        return match ($role) {
            ResourceRole::PRODUCER => $gain > 0 ? "{$resourceName} +{$gain}" : null,
            ResourceRole::CONSUMER, ResourceRole::FINISHER => $cost > 0 ? "{$resourceName} {$cost}消費" : null,
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
                return ['通常攻撃HITで照準+1、MISSで照準+2'];
            }

            $accuracy = max(0, (int) ($metadata['accuracy_delta_points'] ?? 0));
            $pressure = (int) round(max(0.0, (float) ($metadata['sp_pressure_rate'] ?? 0.0)) * 100);

            return $accuracy > 0 && $pressure > 0
                ? ["命中 +{$accuracy}pt", "HIT時：対象最大SPの{$pressure}%を減少（1戦累計15%まで）"]
                : [];
        }

        if ($currentJobId === 60 && $rank === 1) {
            return [
                '通常攻撃HIT：剣勢+1',
                '直接物理攻撃を受ける：剣勢+1',
                '受け流し成功：さらに剣勢+1',
            ];
        }

        if ($currentJobId === 66) {
            if ($rank === 1) {
                return ['通常攻撃HIT：聖護+1', '次の直接ダメージを20%軽減', '実際に1以上軽減：聖護+1'];
            }
            if ($rank === 5) {
                return ['有害状態を全浄化', '浄化成功：聖護+1', '次の直接ダメージを20%軽減'];
            }
            if ($rank === 9) {
                return ['次の直接ダメージを25%軽減'];
            }
        }

        if ($currentJobId === 69 && $rank === 1) {
            return [
                '通常攻撃HIT：指揮点+4',
                '通常攻撃／現在職技の手番：指揮点+1（通常攻撃HIT時は合計+5）',
            ];
        }

        if ($currentJobId === 67 && $rank === 1) {
            return [
                '最大HPの5%を非致死で消費し、最大SPの5%を回復',
                '実変換成立時：触媒+4',
                '通常攻撃HIT：触媒+1',
            ];
        }

        if ($currentJobId === 68) {
            if ($rank === 1) {
                return ['この戦技のHIT時：崩し+4', '通常攻撃HIT：崩し+1'];
            }

            $rate = (int) round(max(0.0, (float) ($metadata['break_rate'] ?? 0.0)) * 100);
            $rounds = max(0, (int) ($metadata['break_rounds'] ?? 0));

            return $rate > 0 && $rounds > 0
                ? ["HIT後：対象のDEF/SPRを{$rate}%低下（{$rounds}ラウンド）", '低下はこの攻撃の次から有効']
                : [];
        }

        return [];
    }

    /**
     * @param  array<string, int|float|string|bool>  $metadata
     * @return array<int, string>
     */
    private function fieldTexts(?int $currentJobId, Skill $skill, array $metadata): array
    {
        if (! $this->featureGate->usesFieldsForCurrentJob($currentJobId)
            || $this->fieldCatalog->forArt($skill) === null
            || ((bool) ($metadata['current_job_only'] ?? false)
                && ! $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill))
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
                    '主場あり：自分の場上書き1～2回で威力+%d%%、3～4回で+%d%%、5回以上で+%d%%',
                    (int) round(((float) ($metadata['field_overwrite_power_multiplier_1_2'] ?? 1.0) - 1.0) * 100),
                    (int) round(((float) ($metadata['field_overwrite_power_multiplier_3_4'] ?? 1.0) - 1.0) * 100),
                    (int) round(((float) ($metadata['field_overwrite_power_multiplier_5_plus'] ?? 1.0) - 1.0) * 100),
                )],
                default => [],
            };
        }

        return match ($operation) {
            'deploy' => $fieldKey !== ''
                ? [$this->fieldCatalog->name($fieldKey).'を展開']
                : [],
            'extend' => ['現在の場を'.max(1, (int) ($metadata['field_extend_rounds'] ?? 1)).'ラウンド延長'],
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
    private function stanceTexts(?int $currentJobId, Skill $skill, array $metadata): array
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
            return [];
        }

        $rank = (int) $skill->learn_rank;
        if ($rank === 1) {
            return ['貫通構えを取る'];
        }

        $rate = (int) round(max(0.0, (float) ($metadata['penetration_rate'] ?? 0)) * 100);
        if ($rank === 5 && $rate > 0) {
            return ["構え時：物理DEF {$rate}%貫通", '使用後、構えを再形成'];
        }
        if ($rank === 9 && $rate > 0) {
            return ["構え時：物理DEF {$rate}%貫通"];
        }

        return [];
    }
}
