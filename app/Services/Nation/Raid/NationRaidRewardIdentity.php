<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;

/** 開催snapshotから、過去開催を改名せずに報酬の固有名を選ぶ。 */
final class NationRaidRewardIdentity
{
    public const ASTRAGIA_DAMAGE_TITLE_TARGET = 'astragia_damage2m';

    public const ASTRAGIA_TOP_THREE_TITLE_TARGET = 'astragia_personal_top3';

    public const ASTRAGIA_ACHIEVEMENT = 'astragia_defeat_participation';

    /** @return array{name:string,target_id:?string} */
    public function personalTitle(NationRaidEvent $event, string $rewardKey, string $legacyName): array
    {
        if (! $this->isAstragia($event)) {
            return ['name' => $legacyName, 'target_id' => null];
        }

        return match ($rewardKey) {
            'damage2m' => ['name' => '天墜機神を穿つ者', 'target_id' => self::ASTRAGIA_DAMAGE_TITLE_TARGET],
            'personal_top3' => ['name' => '天墜機神討滅の功臣', 'target_id' => self::ASTRAGIA_TOP_THREE_TITLE_TARGET],
            default => ['name' => $legacyName, 'target_id' => null],
        };
    }

    public function nationFlagPrefix(NationRaidEvent $event): string
    {
        return $this->isAstragia($event) ? '天墜機神討旗・' : '黒天竜討旗・';
    }

    /** @return array{label:string,achievement:string} */
    public function participationHonor(NationRaidEvent $event): array
    {
        return $this->isAstragia($event)
            ? ['label' => '天墜機神討滅参加', 'achievement' => self::ASTRAGIA_ACHIEVEMENT]
            : ['label' => '黒天竜討滅参加', 'achievement' => 'valgreid_defeat_participation'];
    }

    private function isAstragia(NationRaidEvent $event): bool
    {
        return ! $event->exists
            || ($event->ruleset_snapshot['version'] ?? null) === NationRaidRules::RULESET_VERSION;
    }
}
