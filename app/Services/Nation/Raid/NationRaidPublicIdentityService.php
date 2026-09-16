<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;

/** 開戦前の正体を伏せ、開始時刻から開催snapshotの表示へ切り替える。 */
final class NationRaidPublicIdentityService
{
    public const PREPARING_EVENT_NAME = '次回国家対抗レイド';

    public const PREPARING_BOSS_NAME = '正体不明のレイドボス';

    public const PREPARING_STATUS_LABEL = '次回レイド準備中';

    /** @return array{revealed:bool,event_name:string,boss_name:string,image_path:?string} */
    public function preparing(): array
    {
        return [
            'revealed' => false,
            'event_name' => self::PREPARING_EVENT_NAME,
            'boss_name' => self::PREPARING_BOSS_NAME,
            'image_path' => null,
        ];
    }

    /** @return array{revealed:bool,event_name:string,boss_name:string,image_path:?string} */
    public function forEvent(NationRaidEvent $event): array
    {
        if (! $this->isRevealed($event)) {
            return $this->preparing();
        }

        $imagePath = data_get($event->ruleset_snapshot, 'forms.sealed_scale.image_path');

        return [
            'revealed' => true,
            'event_name' => $event->name,
            'boss_name' => $event->boss_name,
            'image_path' => is_string($imagePath) && $imagePath !== '' ? $imagePath : null,
        ];
    }

    public function isRevealed(NationRaidEvent $event): bool
    {
        return $event->starts_at !== null && now()->gte($event->starts_at);
    }
}
