<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Models\NationAchievement;
use App\Services\Nation\NationAchievementService;
use App\Services\Nation\NationLevelBenefitSettingsService;
use Illuminate\Console\Command;

final class UnlockNationAnniversaryAchievements extends Command
{
    protected $signature = 'nation:unlock-anniversary-achievements';

    protected $description = '建国一周年を迎えた国家へ恒久実績を付与する';

    public function handle(
        NationAchievementService $achievements,
        NationLevelBenefitSettingsService $settings,
    ): int {
        if (! $settings->enabled()) {
            $this->info('国家Lv特典が非公開のため、建国一周年実績の付与をスキップしました。');

            return self::SUCCESS;
        }

        $countBefore = NationAchievement::where('achievement_key', 'first_anniversary')->count();
        Nation::active()->where('founded_at', '<=', now()->subYear())->orderBy('id')->each(
            fn (Nation $nation) => $achievements->recordAnniversaryIfEligible($nation),
        );
        $created = NationAchievement::where('achievement_key', 'first_anniversary')->count() - $countBefore;
        $this->info("建国一周年実績を{$created}国へ付与しました。");

        return self::SUCCESS;
    }
}
