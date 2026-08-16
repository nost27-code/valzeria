<?php

namespace Tests\Unit;

use App\Livewire\MainScreen;
use App\Services\GameTextService;
use App\Services\JobArtService;
use App\Support\FacilityConfig;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class JobArtPvpSetViewTest extends TestCase
{
    public function test_ui_uses_flag_aware_context_slot_and_cost_limits(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/JobArtController.php'));

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString("'pvp' => '対人'", $view);
        $this->assertStringContainsString('availableContexts: @js(array_keys($slotContextLabels))', $view);
        $this->assertStringContainsString("pvp: '対人'", $view);
        $this->assertStringContainsString('@for($slotNo = 1; $slotNo <= $maxSlots; $slotNo++)', $view);
        $this->assertStringContainsString('/{{ $maxCost }}</div>', $view);
        $this->assertStringContainsString('$jobArtService->slotContexts()', $controller);
        $this->assertStringContainsString('$jobArtService->availabilityContextForSlotContext($slotContext)', $controller);
        $this->assertSame(3, JobArtService::MAX_SLOTS);
        $this->assertSame(5, JobArtService::MAX_COST);
    }

    public function test_slot_ui_shows_effective_cost_and_inactive_reason_without_fixed_width(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));
        $slotCard = file_get_contents(resource_path('views/job-arts/partials/slot-card.blade.php'));
        $mainScreen = file_get_contents(app_path('Livewire/MainScreen.php'));

        $this->assertIsString($view);
        $this->assertIsString($slotCard);
        $this->assertIsString($mainScreen);
        $this->assertStringContainsString("getAttribute('job_art_effective_cost')", $view);
        $this->assertStringContainsString("getAttribute('job_art_inactive_reason')", $slotCard);
        $this->assertStringContainsString("'slot_limit' => \$jobArtV2UiEnabled ? '5枠上限' : '枠数上限のため休止'", $slotCard);
        $this->assertStringContainsString("'cost_limit' => \$jobArtV2UiEnabled ? 'Cost上限超過' : 'Cost上限を超えたため休止'", $slotCard);
        $this->assertStringContainsString('data-job-art-inactive-reason', $slotCard);
        $this->assertStringContainsString("'max-w-[680px] space-y-3'", $view);
        $this->assertStringContainsString("'max-w-[560px] space-y-4 px-3'", $view);
        $this->assertStringNotContainsString('min-w-[', $slotCard);
        $this->assertStringContainsString('JobArtService::V2_MAX_SLOTS', $mainScreen);
    }

    public function test_home_menu_uses_job_art_name_and_five_slot_copy(): void
    {
        $mainScreen = file_get_contents(app_path('Livewire/MainScreen.php'));
        $jobArtEntry = collect(FacilityConfig::HOME_ENTRIES)->firstWhere('slug', 'job_arts');

        $this->assertIsString($mainScreen);
        $this->assertStringContainsString("'name' => '戦技セット'", $mainScreen);
        $this->assertStringContainsString('習得した奥義を最大{$jobArtMaxSlots}つまでセットする', $mainScreen);
        $this->assertSame(5, JobArtService::V2_MAX_SLOTS);
        $this->assertSame('戦技セット', $jobArtEntry['label'] ?? null);
        $this->assertSame('戦技セット', $jobArtEntry['default_name'] ?? null);
        $this->assertSame('習得した奥義を最大5つまでセットする', $jobArtEntry['default_desc'] ?? null);
        $this->assertSame('job_arts', FacilityConfig::nameToSlug('home')['戦技セット'] ?? null);
    }

    public function test_home_menu_normalizes_legacy_job_art_overrides_only(): void
    {
        $gameTextService = Mockery::mock(GameTextService::class);
        $gameTextService->shouldReceive('getAllForPrefix')
            ->once()
            ->with('fac.home.')
            ->andReturn([
                'fac.home.job_arts.name' => '奥義',
                'fac.home.job_arts.desc' => '習得した奥義を最大3つまでセットする',
                'fac.home.help.name' => '冒険ガイド',
                'fac.home.help.desc' => '独自の案内文',
            ]);
        $this->app->instance(GameTextService::class, $gameTextService);

        $method = new ReflectionMethod(MainScreen::class, 'applyFacilityOverrides');
        $result = $method->invoke(new MainScreen(), [
            ['name' => '戦技セット', 'desc' => '習得した奥義を最大5つまでセットする'],
            ['name' => 'ヘルプ', 'desc' => '遊び方や施設の説明を確認する'],
        ], 'home');

        $this->assertSame('戦技セット', $result[0]['name']);
        $this->assertSame('習得した奥義を最大5つまでセットする', $result[0]['desc']);
        $this->assertSame('冒険ガイド', $result[1]['name']);
        $this->assertSame('独自の案内文', $result[1]['desc']);
        $this->assertSame(
            '習得した奥義を最大5つまでセットする',
            FacilityConfig::normalizeLegacyOverride(
                'home',
                'job_arts',
                'desc',
                '通常戦用・ボス戦用の奥義をセットする'
            )
        );
    }

    public function test_help_copy_uses_five_slot_normal_boss_and_pvp_sets(): void
    {
        $helpContent = file_get_contents(config_path('help_content.php'));

        $this->assertIsString($helpContent);
        $this->assertStringNotContainsString('最大3つまでセット', $helpContent);
        $this->assertStringContainsString('通常戦用・ボス戦用・PvP用にそれぞれ最大5つまでセット', $helpContent);
    }
}
