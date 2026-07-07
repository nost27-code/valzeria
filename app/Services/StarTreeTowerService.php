<?php

namespace App\Services;

use App\Models\Character;
use App\Models\TowerCharacterRecord;
use App\Models\TowerRun;
use App\Models\TowerRunEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StarTreeTowerService
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_DEFEATED = 'defeated';

    public function __construct(
        private readonly CharacterStatusService $characterStatusService,
        private readonly TowerRankingService $rankingService,
    ) {
    }

    public function towerKey(): string
    {
        return (string) config('star_tree_tower.star_tree.tower_key', 'star_tree_tower');
    }

    /**
     * Centralized UI/text config for this tower.
     *
     * When cloning the tower content, keep behavior code mostly intact and
     * replace the values in config/star_tree_tower.php first.
     *
     * @return array<string,mixed>
     */
    public function uiConfig(): array
    {
        return [
            'name' => $this->displayText('name', '星樹の塔'),
            'log_label' => $this->displayText('log_label', $this->displayText('name', '星樹の塔')),
            'ranking_title' => $this->displayText('ranking_title', $this->displayText('name', '星樹の塔').'ランキング'),
            'location_name' => $this->displayText('location_name', '精霊の森エルフィア'),
            'generic_enemy_name' => $this->displayText('generic_enemy_name', '星樹の魔物'),
            'disabled_message' => $this->displayText('disabled_message', '星樹の塔は現在準備中です。'),
            'locked_message' => $this->displayText('locked_message', '星樹の塔はまだ解放されていません。'),
            'empty_state_title' => $this->displayText('empty_state_title', '星樹の塔は静まり返っている'),
            'exit_label' => $this->displayText('exit_label', $this->displayText('name', '星樹の塔').'から出る'),
            'merchant_name' => $this->displayText('merchant_name', '星灯の行商人'),
            'merchant_found_title' => $this->displayText('merchant_found_title', '星灯の行商人を見つけた'),
            'merchant_intro' => $this->displayText('merchant_intro', '戦いを終えた枝道の先で、小さな灯りを掲げた行商人が待っている。'),
            'merchant_appeared_message' => $this->displayText('merchant_appeared_message', '星灯の行商人が、枝の上に腰かけていた。'),
            'merchant_none_message' => $this->displayText('merchant_none_message', '星灯の行商人はいません。'),
            'merchant_pending_message' => $this->displayText('merchant_pending_message', '星灯の行商人が待っています。購入するか、見送ってから次の階へ進んでください。'),
            'merchant_skipped_message' => $this->displayText('merchant_skipped_message', '星灯の行商人を見送りました。'),
            'public_log_label' => $this->displayText('public_log_label', '星梯の塔'),
            'breath_name' => $this->displayText('breath_name', '星樹の息吹'),
            'scout_flavor' => $this->displayText('scout_flavor', '星樹の気配を読み、次に立ちはだかる相手を見定めた。'),
            'assets' => [
                'symbol' => (string) config('star_tree_tower.star_tree.assets.symbol', 'images/tower/01_tower_symbol.webp'),
                'background' => (string) config('star_tree_tower.star_tree.assets.background', 'images/tower/01_tower.webp'),
                'logo' => (string) config('star_tree_tower.star_tree.assets.logo', 'images/tower/01_tower_logo.webp'),
                'merchant_icon' => (string) config('star_tree_tower.star_tree.assets.merchant_icon', 'images/icon/icon_082.webp'),
            ],
        ];
    }

    public function displayText(string $key, string $default = ''): string
    {
        return (string) config("star_tree_tower.star_tree.display.{$key}", $default);
    }

    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive(
            $this->towerKey(),
            config("extra_content.contents.{$this->towerKey()}")
        );
    }

    public function seasonKey(?CarbonInterface $now = null): string
    {
        $now ??= now(config('app.timezone', 'Asia/Tokyo'));

        return $now->format('o-\WW');
    }

    public function canAccess(Character $character): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $requiredCityId = config('star_tree_tower.star_tree.unlock.required_city_id');

        if ($requiredCityId === null || $requiredCityId === '') {
            return true;
        }

        return (int) ($character->highest_city_id ?? 0) >= (int) $requiredCityId;
    }

    public function getActiveRun(Character $character): ?TowerRun
    {
        return TowerRun::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $this->towerKey())
            ->where('status', self::STATUS_RUNNING)
            ->latest('id')
            ->first();
    }

    public function startRun(Character $character): TowerRun
    {
        if (! $this->canAccess($character)) {
            throw new RuntimeException('Star tree tower is not unlocked for this character.');
        }

        return DB::transaction(function () use ($character): TowerRun {
            $activeRun = $this->getActiveRun($character);
            if ($activeRun) {
                return $activeRun;
            }

            $now = now(config('app.timezone', 'Asia/Tokyo'));
            $startFloor = $this->checkpointStartFloor($character);

            return $this->createRun($character, $startFloor, $now);
        });
    }

    public function restartFromFirstFloor(Character $character): TowerRun
    {
        if (! $this->canAccess($character)) {
            throw new RuntimeException('Star tree tower is not unlocked for this character.');
        }

        return DB::transaction(function () use ($character): TowerRun {
            $now = now(config('app.timezone', 'Asia/Tokyo'));
            $activeRun = $this->getActiveRun($character);

            if ($activeRun) {
                $activeRun->forceFill([
                    'status' => self::STATUS_RETURNED,
                    'ended_at' => $now,
                    'last_event_type' => 'restart',
                ])->save();

                TowerRunEvent::query()->create([
                    'tower_run_id' => $activeRun->id,
                    'character_id' => $activeRun->character_id,
                    'floor' => max(1, (int) $activeRun->current_floor),
                    'event_type' => 'restart',
                    'result' => 'restarted',
                    'hp_after' => $activeRun->tower_current_hp,
                    'mp_after' => $activeRun->tower_current_mp,
                    'message' => $this->displayText('name', '星樹の塔').'を1階から登り直しました。',
                ]);
            }

            return $this->createRun($character, 1, $now);
        });
    }

    public function checkpointStartFloor(Character $character): int
    {
        $bestClearedFloor = (int) (TowerCharacterRecord::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $this->towerKey())
            ->value('best_cleared_floor') ?? 0);

        $checkpointClearedFloor = intdiv($bestClearedFloor, 10) * 10;
        $maxFloor = max(1, (int) config('star_tree_tower.star_tree.seed_floor_count', 100));

        return min($checkpointClearedFloor + 1, $maxFloor);
    }

    public function recordFloorCleared(
        Character $character,
        TowerRun $run,
        int $floor,
        ?int $hpAfter = null,
        ?int $mpAfter = null,
        int $staminaSpent = 0,
    ): TowerRun {
        $this->assertRunBelongsToCharacter($character, $run);
        $this->assertRunning($run);

        if ((int) $run->current_floor !== $floor) {
            throw new InvalidArgumentException('Cleared floor must match the run current floor.');
        }

        return DB::transaction(function () use ($run, $floor, $hpAfter, $mpAfter, $staminaSpent): TowerRun {
            $run->forceFill([
                'cleared_floor' => max((int) $run->cleared_floor, $floor),
                'current_floor' => $floor + 1,
                'reached_floor' => max((int) $run->reached_floor, $floor + 1),
                'tower_current_hp' => $hpAfter ?? $run->tower_current_hp,
                'tower_current_mp' => $mpAfter ?? $run->tower_current_mp,
                'total_wins' => (int) $run->total_wins + 1,
                'stamina_spent' => (int) $run->stamina_spent + max(0, $staminaSpent),
                'last_event_type' => 'battle',
            ])->save();

            $run->refresh();
            $this->rankingService->recordFloorCleared($run);

            return $run;
        });
    }

    public function returnFromTower(Character $character, TowerRun $run): TowerRun
    {
        $this->assertRunBelongsToCharacter($character, $run);
        $this->assertRunning($run);

        return DB::transaction(function () use ($run): TowerRun {
            $now = now(config('app.timezone', 'Asia/Tokyo'));

            $run->forceFill([
                'status' => self::STATUS_RETURNED,
                'ended_at' => $now,
                'last_event_type' => 'return',
            ])->save();

            TowerRunEvent::query()->create([
                'tower_run_id' => $run->id,
                'character_id' => $run->character_id,
                'floor' => max(1, (int) $run->cleared_floor),
                'event_type' => 'return',
                'result' => 'returned',
                'hp_after' => $run->tower_current_hp,
                'mp_after' => $run->tower_current_mp,
            ]);

            $run->refresh();
            $this->rankingService->recordRunReturned($run, $now);

            return $run;
        });
    }

    public function pauseRun(Character $character, TowerRun $run): TowerRun
    {
        $this->assertRunBelongsToCharacter($character, $run);
        $this->assertRunning($run);

        return DB::transaction(function () use ($run): TowerRun {
            $run->forceFill([
                'last_event_type' => 'pause',
            ])->save();

            TowerRunEvent::query()->create([
                'tower_run_id' => $run->id,
                'character_id' => $run->character_id,
                'floor' => max(1, (int) $run->current_floor),
                'event_type' => 'pause',
                'result' => 'paused',
                'hp_after' => $run->tower_current_hp,
                'mp_after' => $run->tower_current_mp,
                'message' => $this->displayText('name', '星樹の塔').'からいったん外へ出ました。',
            ]);

            return $run->refresh();
        });
    }

    public function finishAsDefeated(
        Character $character,
        TowerRun $run,
        int $failedFloor,
        ?int $hpAfter = null,
        ?int $mpAfter = null,
    ): TowerRun {
        $this->assertRunBelongsToCharacter($character, $run);
        $this->assertRunning($run);

        return DB::transaction(function () use ($run, $failedFloor, $hpAfter, $mpAfter): TowerRun {
            $now = now(config('app.timezone', 'Asia/Tokyo'));

            $run->forceFill([
                'status' => self::STATUS_DEFEATED,
                'failed_floor' => $failedFloor,
                'reached_floor' => max((int) $run->reached_floor, $failedFloor),
                'tower_current_hp' => $hpAfter ?? $run->tower_current_hp,
                'tower_current_mp' => $mpAfter ?? $run->tower_current_mp,
                'total_losses' => (int) $run->total_losses + 1,
                'ended_at' => $now,
                'last_event_type' => 'defeat',
            ])->save();

            TowerRunEvent::query()->create([
                'tower_run_id' => $run->id,
                'character_id' => $run->character_id,
                'floor' => $failedFloor,
                'event_type' => 'defeat',
                'result' => 'defeated',
                'hp_after' => $hpAfter,
                'mp_after' => $mpAfter,
            ]);

            $run->refresh();
            $this->rankingService->recordRunDefeated($run, $now);

            return $run;
        });
    }

    private function assertRunBelongsToCharacter(Character $character, TowerRun $run): void
    {
        if ((int) $run->character_id !== (int) $character->id) {
            throw new InvalidArgumentException('Tower run does not belong to the character.');
        }
    }

    private function assertRunning(TowerRun $run): void
    {
        if ($run->status !== self::STATUS_RUNNING) {
            throw new InvalidArgumentException('Tower run is not running.');
        }
    }

    private function createRun(Character $character, int $startFloor, CarbonInterface $now): TowerRun
    {
        $stats = $this->characterStatusService->getFinalStats($character);
        $startFloor = max(1, $startFloor);
        $clearedFloor = max(0, $startFloor - 1);

        $run = TowerRun::query()->create([
            'character_id' => $character->id,
            'tower_key' => $this->towerKey(),
            'season_key' => $this->seasonKey($now),
            'status' => self::STATUS_RUNNING,
            'current_floor' => $startFloor,
            'reached_floor' => $startFloor,
            'cleared_floor' => $clearedFloor,
            'failed_floor' => null,
            'tower_max_hp' => (int) ($stats['max_hp'] ?? 1),
            'tower_current_hp' => (int) ($stats['max_hp'] ?? 1),
            'tower_max_mp' => (int) ($stats['max_mp'] ?? 0),
            'tower_current_mp' => (int) ($stats['max_mp'] ?? 0),
            'total_wins' => 0,
            'total_losses' => 0,
            'merchant_encounter_count' => 0,
            'gold_spent' => 0,
            'stamina_spent' => 0,
            'started_at' => $now,
        ]);

        $this->rankingService->recordRunStarted($character, $run->tower_key);

        return $run;
    }
}
