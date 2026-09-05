<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationAchievement;
use App\Models\NationMembership;
use App\Models\NationWarHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NationAchievementService
{
    /** @var array<string, array{name:string,description:string}> */
    private const CATALOG = [
        'valgreid_defeat_participation' => ['name' => '黒天竜討滅参加', 'description' => '国家対抗レイドで黒天竜の討滅に参加した。'],
        'first_donation' => ['name' => 'はじめての納品', 'description' => '国家へ初めて都市素材を納品した。'],
        'first_member_joined' => ['name' => '新たな仲間', 'description' => '建国者以外の国民が初めて加入した。'],
        'first_facility_upgrade' => ['name' => '要塞の礎', 'description' => '国家施設を初めて強化した。'],
        'nation_level_5' => ['name' => '芽吹く国家', 'description' => '国家Lv5へ到達した。'],
        'nation_level_10' => ['name' => '集いの旗印', 'description' => '国家Lv10へ到達した。'],
        'nation_level_15' => ['name' => '歴史の始まり', 'description' => '国家Lv15へ到達した。'],
        'nation_level_20' => ['name' => '発展する国庫', 'description' => '国家Lv20へ到達した。'],
        'nation_level_25' => ['name' => '確かな結束', 'description' => '国家Lv25へ到達した。'],
        'nation_level_30' => ['name' => '大国への歩み', 'description' => '国家Lv30へ到達した。'],
        'nation_level_35' => ['name' => '黄金の時代', 'description' => '国家Lv35へ到達した。'],
        'nation_level_40' => ['name' => '民の集う大国', 'description' => '国家Lv40へ到達した。'],
        'nation_level_45' => ['name' => '誉れ高き国家', 'description' => '国家Lv45へ到達した。'],
        'nation_level_50' => ['name' => '国家発展の極致', 'description' => '国家Lv50へ到達した。'],
        'first_war_participation' => ['name' => '初陣', 'description' => '国家戦へ初めて参加した。'],
        'first_war_win' => ['name' => '最初の勝利', 'description' => '国家戦で初勝利を収めた。'],
        'first_anniversary' => ['name' => '建国一周年', 'description' => '建国から一年を迎えた。'],
    ];

    public function __construct(
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationLevelBenefitSettingsService $settings,
    ) {}

    /** @return array<string, array{name:string,description:string}> */
    public function catalog(): array
    {
        return self::CATALOG;
    }

    public function unlock(Nation $nation, string $key, array $metadata = [], ?CarbonInterface $at = null): NationAchievement
    {
        throw_unless(isset(self::CATALOG[$key]), \DomainException::class, '国家実績の種類が不正です。');

        $achievement = NationAchievement::firstOrCreate(
            ['nation_id' => $nation->id, 'achievement_key' => $key],
            [
                'unlocked_at' => $at ?? now(),
                'metadata' => $metadata === [] ? null : $metadata,
            ],
        );

        if ($achievement->wasRecentlyCreated) {
            $this->activityLogs->record($nation, 'nation_achievement_unlocked', null, null, [
                'achievement_key' => $key,
                'achievement_name' => self::CATALOG[$key]['name'],
            ]);
        }

        return $achievement;
    }

    public function recordDonationAndLevelUps(Nation $nation, int $previousLevel, int $currentLevel): void
    {
        $this->unlock($nation, 'first_donation');
        foreach (array_keys(config('nation_development.benefit_milestones', [])) as $milestoneLevel) {
            $milestoneLevel = (int) $milestoneLevel;
            if ($milestoneLevel <= 1 || $milestoneLevel <= $previousLevel || $milestoneLevel > $currentLevel) {
                continue;
            }
            $this->unlock($nation, "nation_level_{$milestoneLevel}", ['level' => $milestoneLevel]);
        }
    }

    public function recordMemberJoined(Nation $nation): void
    {
        if ($nation->memberships()->count() >= 2) {
            $this->unlock($nation, 'first_member_joined');
        }
    }

    public function recordFacilityUpgrade(Nation $nation): void
    {
        $this->unlock($nation, 'first_facility_upgrade');
    }

    public function recordWarResolved(NationWarHistory $history): void
    {
        foreach ([$history->declaring_nation_id, $history->defending_nation_id] as $nationId) {
            $nation = Nation::findOrFail($nationId);
            $hasEarlierWar = NationWarHistory::query()
                ->whereKeyNot($history->id)
                ->where(function ($query) use ($nationId): void {
                    $query->where('declaring_nation_id', $nationId)
                        ->orWhere('defending_nation_id', $nationId);
                })
                ->where(function ($query) use ($history): void {
                    $query->where('resolved_at', '<', $history->resolved_at)
                        ->orWhere(function ($sameTime) use ($history): void {
                            $sameTime->where('resolved_at', $history->resolved_at)
                                ->where('id', '<', $history->id);
                        });
                })
                ->exists();
            if (! $hasEarlierWar) {
                $this->unlock($nation, 'first_war_participation', ['nation_war_history_id' => $history->id], $history->resolved_at);
            }
        }

        if ($history->winner_nation_id === null) {
            return;
        }

        $hasEarlierWin = NationWarHistory::query()
            ->whereKeyNot($history->id)
            ->where('winner_nation_id', $history->winner_nation_id)
            ->where(function ($query) use ($history): void {
                $query->where('resolved_at', '<', $history->resolved_at)
                    ->orWhere(function ($sameTime) use ($history): void {
                        $sameTime->where('resolved_at', $history->resolved_at)
                            ->where('id', '<', $history->id);
                    });
            })
            ->exists();
        if (! $hasEarlierWin) {
            $this->unlock(
                Nation::findOrFail($history->winner_nation_id),
                'first_war_win',
                ['nation_war_history_id' => $history->id],
                $history->resolved_at,
            );
        }
    }

    public function recordAnniversaryIfEligible(Nation $nation): void
    {
        if ($nation->founded_at?->lte(now()->subYear())) {
            $this->unlock($nation, 'first_anniversary');
        }
    }

    /** @param list<string> $keys @return Collection<int, NationAchievement> */
    public function setShowcase(NationMembership $actor, array $keys): Collection
    {
        $this->settings->assertEnabled();
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));

        return DB::transaction(function () use ($actor, $keys): Collection {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = NationMembership::whereKey($actor->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();
            throw_unless($lockedActor, \DomainException::class, '国家の所属情報が変更されました。');
            $this->roles->authorize($lockedActor, 'manage_showcase');
            $slotLimit = $this->levels->benefitsForLevel(
                $this->levels->levelFor((int) $nation->development_exp),
            )['showcase_slots'];
            throw_if(count($keys) > $slotLimit, \DomainException::class, "現在の国家Lvでは実績を{$slotLimit}件まで展示できます。");
            $achievements = NationAchievement::where('nation_id', $nation->id)
                ->whereIn('achievement_key', $keys)
                ->lockForUpdate()
                ->get()
                ->keyBy('achievement_key');
            throw_unless($achievements->count() === count($keys), \DomainException::class, '未獲得の国家実績は展示できません。');

            NationAchievement::where('nation_id', $nation->id)->update(['display_position' => null]);
            foreach ($keys as $index => $key) {
                $achievements->get($key)->update(['display_position' => $index + 1]);
            }
            $this->activityLogs->record($nation, 'nation_showcase_changed', $lockedActor->character, null, [
                'achievement_keys' => $keys,
            ]);

            return $this->displayedFor($nation);
        }, 3);
    }

    /** @return Collection<int, NationAchievement> */
    public function displayedFor(Nation $nation): Collection
    {
        return NationAchievement::where('nation_id', $nation->id)
            ->whereNotNull('display_position')
            ->orderBy('display_position')
            ->get();
    }

    /** @return array{name:string,description:string} */
    public function presentation(NationAchievement|string $achievement): array
    {
        $key = $achievement instanceof NationAchievement ? $achievement->achievement_key : $achievement;

        return self::CATALOG[$key] ?? ['name' => '国家実績', 'description' => '国家の歩みとして残された実績。'];
    }
}
