<?php

namespace App\Services;

use App\Enums\SixHeroBattleMode;
use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroSeason;
use App\Services\Battle\PvPBattleResolution;
use App\Support\PlayerStatLabel;
use App\Support\SixHeroCompetitionRules;
use Carbon\CarbonImmutable;

final class SixHeroBattleResultPresenter
{
    public function __construct(
        private readonly CharacterStatusService $characterStatusService,
        private readonly CharacterIconSetService $characterIconSetService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function official(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
        SixHeroOfficialBattleResult $result,
    ): array {
        $summary = $this->baseSummary(
            $season,
            $room,
            $attacker,
            $defender,
            $result->resolution,
            SixHeroBattleMode::OFFICIAL,
        );
        $rankChange = $result->rankChange;

        return array_merge($summary, [
            'rankChanged' => $rankChange?->rankChanged ?? false,
            'rankChangeStatus' => match (true) {
                $rankChange === null => 'not_applied',
                $rankChange->rankChanged => 'changed',
                $rankChange->attackerWon => 'unchanged_concurrent',
                default => 'unchanged_loss',
            },
            'attackerOldRank' => $rankChange?->attackerOldRank,
            'attackerNewRank' => $rankChange?->attackerNewRank,
            'defenderOldRank' => $rankChange?->defenderOldRank,
            'defenderNewRank' => $rankChange?->defenderNewRank,
            'officialAttemptsRemaining' => $result->officialAttemptsRemaining,
            'officialAttemptLimit' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function practice(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
        SixHeroPracticeBattleResult $result,
    ): array {
        return array_merge(
            $this->baseSummary(
                $season,
                $room,
                $attacker,
                $defender,
                $result->resolution,
                SixHeroBattleMode::PRACTICE,
            ),
            [
                'rankChanged' => false,
                'rankChangeStatus' => 'practice',
                'attackerOldRank' => null,
                'attackerNewRank' => null,
                'defenderOldRank' => null,
                'defenderNewRank' => null,
                'officialAttemptsRemaining' => null,
                'officialAttemptLimit' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseSummary(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
        PvPBattleResolution $resolution,
        SixHeroBattleMode $mode,
    ): array {
        return [
            'mode' => $mode->value,
            'modeLabel' => $mode->label(),
            'seasonKey' => (string) $season->season_key,
            'seasonLabel' => CarbonImmutable::instance($season->starts_at)
                ->setTimezone((string) config('app.timezone'))
                ->format('Y年n月期'),
            'roomKey' => $room->value,
            'roomLabel' => $room->label(),
            'attackerName' => (string) $attacker->name,
            'defenderName' => (string) $defender->name,
            'attackerCombatant' => $this->combatantSummary($attacker),
            'defenderCombatant' => $this->combatantSummary($defender),
            'attackerWon' => $resolution->attackerWon,
            'outcomeLabel' => $resolution->attackerWon ? '勝利！' : '敗北',
            'turnCount' => max(0, $resolution->turnCount),
            'attackerHp' => $this->hpSummary(
                $resolution->attackerHp,
                $resolution->attackerMaxHp,
            ),
            'defenderHp' => $this->hpSummary(
                $resolution->defenderHp,
                $resolution->defenderMaxHp,
            ),
            'logs' => $this->styledBattleLogs($resolution->result->logs),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     iconPath: string,
     *     jobName: string,
     *     jobLevel: int,
     *     level: int,
     *     stats: list<array{label: string, value: int}>,
     *     equipment: list<array{slot: string, rank: string, name: string, icon: ?string}>
     * }
     */
    private function combatantSummary(Character $character): array
    {
        $character->loadMissing('jobClass:id,name');
        $stats = $this->characterStatusService->getFinalStats($character);
        $jobLevel = $character->current_job_id === null
            ? 1
            : (int) ($character->jobHistories()
                ->where('job_class_id', $character->current_job_id)
                ->value('job_level') ?? 1);

        return [
            'name' => (string) $character->name,
            'iconPath' => $this->characterIconSetService->pathFor($character, 'battle'),
            'jobName' => (string) ($character->jobClass?->name ?? '冒険者'),
            'jobLevel' => max(1, $jobLevel),
            'level' => max(1, (int) ($character->level ?? 1)),
            'stats' => [
                ['label' => PlayerStatLabel::for('max_hp'), 'value' => (int) ($stats['max_hp'] ?? 1)],
                ['label' => PlayerStatLabel::for('max_mp'), 'value' => (int) ($stats['max_mp'] ?? 0)],
                ['label' => PlayerStatLabel::for('str'), 'value' => (int) ($stats['str'] ?? 1)],
                ['label' => PlayerStatLabel::for('def'), 'value' => (int) ($stats['def'] ?? 0)],
                ['label' => PlayerStatLabel::for('mag'), 'value' => (int) ($stats['mag'] ?? 0)],
                ['label' => PlayerStatLabel::for('spr'), 'value' => (int) ($stats['spr'] ?? 0)],
                ['label' => PlayerStatLabel::for('agi'), 'value' => (int) ($stats['agi'] ?? 1)],
                ['label' => PlayerStatLabel::for('luk'), 'value' => (int) ($stats['luk'] ?? 0)],
            ],
            'equipment' => $this->equipmentSummary($character),
        ];
    }

    /**
     * @return list<array{slot: string, rank: string, name: string, icon: ?string}>
     */
    private function equipmentSummary(Character $character): array
    {
        $slotOrder = ['weapon' => 0, 'armor' => 1, 'accessory' => 2];

        return $character->characterItems()
            ->where('is_equipped', true)
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->get()
            ->filter(fn ($characterItem): bool => $characterItem->item !== null)
            ->sortBy(fn ($characterItem): array => [
                $slotOrder[(string) $characterItem->item->type] ?? 99,
                $characterItem->id,
            ])
            ->map(function ($characterItem): array {
                $type = (string) $characterItem->item->type;
                $rank = match ($type) {
                    'weapon' => $characterItem->item->weapon_rank ?? $characterItem->item->rarity,
                    'armor' => $characterItem->item->armor_rank ?? $characterItem->item->rarity,
                    'accessory' => $characterItem->item->accessory_rank ?? $characterItem->item->rarity,
                    default => $characterItem->item->rarity,
                };

                return [
                    'slot' => match ($type) {
                        'weapon' => '武器',
                        'armor' => '防具',
                        'accessory' => '装飾品',
                        default => '装備',
                    },
                    'rank' => strtoupper((string) $rank),
                    'name' => $characterItem->displayName(false),
                    'icon' => $characterItem->item->iconImagePath(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{current: int, max: int, percent: int}
     */
    private function hpSummary(int $currentHp, int $maxHp): array
    {
        $safeMaxHp = max(1, $maxHp);
        $safeCurrentHp = min($safeMaxHp, max(0, $currentHp));

        return [
            'current' => $safeCurrentHp,
            'max' => $safeMaxHp,
            'percent' => min(
                100,
                max(0, (int) round(($safeCurrentHp / $safeMaxHp) * 100)),
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $logs
     * @return array<int, string>
     */
    public function styledBattleLogs(array $logs): array
    {
        $safeLogs = [];

        foreach ($logs as $log) {
            if (! is_scalar($log)) {
                continue;
            }

            $safe = $this->sanitizeBattleLog((string) $log);
            if ($safe !== '') {
                $safeLogs[] = $safe;
            }
        }

        return $safeLogs;
    }

    private function sanitizeBattleLog(string $log): string
    {
        $parts = preg_split('/(<[^>]*>)/u', $log, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return trim(htmlspecialchars(
                $log,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
                false,
            ));
        }

        $safe = '';
        $openSpans = 0;
        $openButtons = 0;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^<br\s*\/?\s*>$/i', $part) === 1) {
                $safe .= '<br>';

                continue;
            }

            if (preg_match('/^<\/span\s*>$/i', $part) === 1) {
                if ($openSpans > 0) {
                    $safe .= '</span>';
                    $openSpans--;
                }

                continue;
            }

            if (preg_match('/^<\/button\s*>$/i', $part) === 1) {
                if ($openButtons > 0) {
                    $safe .= '</button>';
                    $openButtons--;
                }

                continue;
            }

            if (preg_match('/^<span\s+class\s*=\s*(["\'])([^"\']*)\1\s*>$/i', $part, $matches) === 1) {
                $classes = preg_split('/\s+/', trim($matches[2]));
                if ($classes !== false && $classes !== [] && $this->areSafeBattleLogClasses($classes)) {
                    $safe .= '<span class="'.implode(' ', $classes).'">';
                    $openSpans++;
                }

                continue;
            }

            if (preg_match(
                '/^<button type="button" class="([^"]+)" aria-expanded="false">$/i',
                $part,
                $matches,
            ) === 1) {
                $classes = preg_split('/\s+/', trim($matches[1]));
                if ($classes !== false
                    && in_array('battle-log-job-art-tooltip-trigger', $classes, true)
                    && $this->areSafeBattleLogClasses($classes)
                ) {
                    $safe .= '<button type="button" class="'.implode(' ', $classes).'" aria-expanded="false">';
                    $openButtons++;
                }

                continue;
            }

            if (str_starts_with($part, '<')) {
                continue;
            }

            $safe .= htmlspecialchars(
                $part,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
                false,
            );
        }

        if ($openSpans > 0) {
            if ($openButtons > 0) {
                $safe .= str_repeat('</button>', $openButtons);
            }

            $safe .= str_repeat('</span>', $openSpans);
        } elseif ($openButtons > 0) {
            $safe .= str_repeat('</button>', $openButtons);
        }

        return trim($safe);
    }

    /**
     * @param  list<string>  $classes
     */
    private function areSafeBattleLogClasses(array $classes): bool
    {
        foreach ($classes as $class) {
            if (preg_match('/^battle-log-[a-z0-9_-]+$/', $class) === 1) {
                continue;
            }

            if (preg_match('/^font-(?:normal|medium|semibold|bold|extrabold|black)$/', $class) === 1) {
                continue;
            }

            if (preg_match('/^text-(?:xs|sm|base|lg|xl|[2-6]xl)$/', $class) === 1) {
                continue;
            }

            if (preg_match('/^text-(?:black|white)$/', $class) === 1) {
                continue;
            }

            if (preg_match(
                '/^text-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(?:50|100|200|300|400|500|600|700|800|900|950)$/',
                $class,
            ) === 1) {
                continue;
            }

            return false;
        }

        return true;
    }
}
