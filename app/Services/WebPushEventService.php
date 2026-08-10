<?php

namespace App\Services;

use App\Models\Character;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WebPushEventService
{
    public function __construct(
        private readonly WebPushEligibilityService $eligibility,
        private readonly WebPushPreferenceService $preferences,
        private readonly ExplorationStaminaService $stamina,
        private readonly CharacterNotificationService $notifications
    ) {}

    public function generate(): int
    {
        if (! $this->stamina->enabled()
            || ! $this->eligibility->isConfigured()
            || ! Schema::hasTable('web_push_subscriptions')
            || ! Schema::hasTable('character_notifications')
            || ! Schema::hasTable('character_web_push_preferences')) {
            return 0;
        }

        $generated = 0;
        $batchSize = min(500, max(100, (int) config('web_push.batch_size', 20)));

        Character::query()
            ->with(['user', 'webPushPreference'])
            ->whereIn('id', WebPushSubscription::query()->select('character_id'))
            ->orderBy('id')
            ->chunkById($batchSize, function ($characters) use (&$generated): void {
                foreach ($characters as $character) {
                    if (! $this->eligibility->isAllowed($character)) {
                        continue;
                    }

                    $shouldNotify = $this->preferences->isEnabled($character, 'exploration_stamina_full');
                    $max = $this->stamina->maxForCharacter($character);
                    $before = max(0, (int) ($character->explore_stamina ?? $max));
                    if ($before >= $max || (int) $this->stamina->summary($character)['current'] < $max) {
                        continue;
                    }

                    $created = DB::transaction(function () use ($character, $shouldNotify): bool {
                        $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->first();
                        if (! $locked instanceof Character) {
                            return false;
                        }
                        $locked->setRelation('user', $character->user);

                        $max = $this->stamina->maxForCharacter($locked);
                        $before = max(0, (int) ($locked->explore_stamina ?? $max));
                        if ($before >= $max) {
                            return false;
                        }

                        $this->stamina->recover($locked, false);
                        if ((int) $locked->explore_stamina < $max) {
                            return false;
                        }

                        $locked->save();
                        if (! $shouldNotify) {
                            return false;
                        }

                        $notification = $this->notifications->create(
                            $locked,
                            'adventure',
                            'exploration_stamina_full',
                            '探索力が最大まで回復しました',
                            '探索力がMAXになりました。冒険を再開できます。',
                            '冒険する',
                            '/home'
                        );

                        if ($notification === null) {
                            throw new RuntimeException('Failed to create the exploration stamina notification.');
                        }

                        return true;
                    });

                    if ($created) {
                        $generated++;
                    }
                }
            });

        return $generated;
    }
}
