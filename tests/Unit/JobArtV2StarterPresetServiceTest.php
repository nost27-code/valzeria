<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\Skill;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2OfficialPresetCatalog;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceCatalog;
use App\Services\JobArtV2SlotConditionCatalog;
use App\Services\JobArtV2StarterPresetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobArtV2StarterPresetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('skills');
        Schema::dropIfExists('job_classes');
        Schema::create('job_classes', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('rank')->default('normal');
        });
        Schema::create('skills', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('job_id');
            $table->string('name');
            $table->string('skill_type')->default('job_art');
            $table->unsignedTinyInteger('learn_rank');
            $table->unsignedTinyInteger('art_cost');
            $table->string('effect_template')->nullable();
            $table->string('limit_group')->nullable();
            $table->boolean('inherit_on_master')->default(true);
            $table->boolean('pve_enabled')->default(true);
            $table->boolean('boss_enabled')->default(true);
            $table->boolean('champ_enabled')->default(true);
        });
        $this->seedCatalogMaster();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.c_design_prototype' => true,
            'battle.job_art_v2.ultimate_counterplay' => true,
            'battle.job_art_v2.pvp_set' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('skills');
        Schema::dropIfExists('job_classes');
        parent::tearDown();
    }

    #[DataProvider('lineageJobProvider')]
    public function test_all_ten_lineages_resolve_three_complete_crown_presets(int $jobId, string $lineage): void
    {
        $keys = collect(['finisher', 'cycle', 'tactical'])
            ->flatMap(fn (string $style): array => config("job_art_official_presets.{$lineage}.{$style}.variants.crown.skills"))
            ->unique()
            ->values()
            ->all();
        $service = $this->service($this->arts($keys, $jobId));
        $presets = $service->presetsForDisplay($this->character($jobId), 'normal');
        $lineagePresets = collect($presets)->where('lineage_key', $lineage)->values()->all();

        $this->assertCount(30, $presets);
        $this->assertSame(['finisher', 'cycle', 'tactical'], array_column($lineagePresets, 'style_key'));
        $this->assertCount(27, collect($presets)->where('can_apply', false));
        foreach ($lineagePresets as $preset) {
            $this->assertTrue($preset['can_apply'], "{$lineage} {$preset['style_key']}");
            $this->assertSame('COMPLETE', $preset['status']);
            $this->assertSame('crown', $preset['current_variant']['key']);
            $this->assertSame(5, $preset['current_variant']['learned_count']);
            $this->assertLessThanOrEqual(9, $preset['current_variant']['cost']);
            $this->assertCount(5, $preset['current_variant']['arts']);
        }
    }

    public static function lineageJobProvider(): array
    {
        return [
            'counter' => [60, 'counter'],
            'eclipse' => [61, 'eclipse'],
            'pierce' => [62, 'pierce'],
            'field' => [63, 'field'],
            'hunt' => [64, 'hunt'],
            'aim' => [65, 'aim'],
            'guard' => [66, 'guard'],
            'transmute' => [67, 'transmute'],
            'break' => [68, 'break'],
            'command' => [69, 'command'],
        ];
    }

    public function test_one_current_job_can_complete_and_apply_presets_from_all_ten_lineages(): void
    {
        $keys = collect(config('job_art_official_presets'))
            ->flatMap(fn (array $presets): array => collect($presets)
                ->flatMap(fn (array $preset): array => $preset['variants']['crown']['skills'])
                ->all())
            ->unique()
            ->values()
            ->all();
        [$service, $jobArtService] = $this->serviceWithJobArt($this->arts($keys, 60));
        $character = $this->character(60);
        $presets = $service->presetsForDisplay($character, 'normal');

        $this->assertCount(30, $presets);
        $this->assertCount(30, collect($presets)->where('can_apply', true));
        $this->assertCount(10, collect($presets)->pluck('lineage_key')->unique());

        $service->apply($character, 'field', JobArtV2StarterPresetService::CYCLE, 'normal', 'crown');
        $this->assertSame(
            array_map(fn (string $key): int => $this->skillId($key), config('job_art_official_presets.field.cycle.variants.crown.skills')),
            array_values($jobArtService->saved['normal']['slots']),
        );
    }

    public function test_all_ninety_explicit_variants_can_be_resolved_and_applied_without_substitution(): void
    {
        foreach (self::lineageJobProvider() as [$jobId, $lineage]) {
            foreach (['finisher', 'cycle', 'tactical'] as $style) {
                foreach (['advanced', 'super', 'crown'] as $variant) {
                    $keys = config("job_art_official_presets.{$lineage}.{$style}.variants.{$variant}.skills");
                    [$service, $jobArtService] = $this->serviceWithJobArt($this->arts($keys, $jobId));
                    $service->apply($this->character($jobId), $lineage, $style, 'normal', $variant);

                    $this->assertSame(
                        array_map(fn (string $key): int => $this->skillId($key), $keys),
                        array_values($jobArtService->saved['normal']['slots']),
                        "{$lineage}:{$style}:{$variant}",
                    );
                }
            }
        }
    }

    public function test_only_the_highest_fully_learned_variant_becomes_current(): void
    {
        $advanced = config('job_art_official_presets.counter.tactical.variants.advanced.skills');
        $service = $this->service($this->arts($advanced, 60));
        $preset = collect($service->presetsForDisplay($this->character(60), 'normal'))
            ->where('lineage_key', 'counter')
            ->keyBy('style_key')['tactical'];

        $this->assertTrue($preset['can_apply']);
        $this->assertSame('LOWER_VARIANT_AVAILABLE', $preset['status']);
        $this->assertSame('advanced', $preset['current_variant']['key']);
        $this->assertSame('super', $preset['next_variant']['key']);
        $this->assertSame('crown', $preset['completion_variant']['key']);
        $this->assertGreaterThan(0, $preset['completion_variant']['missing_count']);
    }

    public function test_unlearned_art_is_shown_but_never_partially_applied_or_substituted(): void
    {
        $keys = config('job_art_official_presets.counter.finisher.variants.advanced.skills');
        array_pop($keys);
        [$service, $jobArtService] = $this->serviceWithJobArt($this->arts($keys, 60));
        $preset = collect($service->presetsForDisplay($this->character(60), 'normal'))
            ->where('lineage_key', 'counter')
            ->keyBy('style_key')['finisher'];

        $this->assertFalse($preset['can_apply']);
        $this->assertSame('LOCKED', $preset['status']);
        $this->assertSame(4, $preset['next_variant']['learned_count']);
        $this->assertSame(1, $preset['next_variant']['missing_count']);
        $this->assertCount(5, $preset['next_variant']['arts']);
        $this->assertCount(1, collect($preset['next_variant']['arts'])->where('is_learned', false));

        try {
            $service->apply($this->character(60), 'counter', JobArtV2StarterPresetService::FINISHER, 'normal', 'advanced');
            $this->fail('Incomplete official preset must not be partially applied.');
        } catch (ValidationException) {
            $this->assertSame([], $jobArtService->saved);
        }
    }

    public function test_apply_uses_exact_variant_order_and_counterplay_condition(): void
    {
        $keys = config('job_art_official_presets.counter.tactical.variants.crown.skills');
        [$service, $jobArtService] = $this->serviceWithJobArt($this->arts($keys, 60));
        $character = $this->character(60);

        $service->apply($character, 'counter', JobArtV2StarterPresetService::TACTICAL, 'boss', 'crown');

        $expectedIds = array_map(fn (string $key): int => $this->skillId($key), $keys);
        $this->assertSame($expectedIds, array_values($jobArtService->saved['boss']['slots']));
        $this->assertSame(['normal', 'normal', 'normal', 'normal', 'normal'], array_values($jobArtService->saved['boss']['policies']));
        $this->assertSame(
            ['always', 'always', 'opponent_ultimate_preparing', 'always', 'always'],
            array_values($jobArtService->saved['boss']['conditions']),
        );
    }

    public function test_obsolete_c_design_flag_does_not_hide_presets_and_unsupported_jobs_fail_closed_without_randomness(): void
    {
        $keys = config('job_art_official_presets.counter.finisher.variants.crown.skills');
        $service = $this->service($this->arts($keys, 60));
        $character = $this->character(60);
        srand(7319);
        $expected = rand();
        srand(7319);

        config(['battle.job_art_v2.c_design_prototype' => false]);
        $this->assertCount(30, $service->presetsForDisplay($character, 'normal'));

        config(['battle.job_art_v2.c_design_prototype' => true]);
        $this->assertSame([], $service->presetsForDisplay($this->character(39), 'normal'));
        $this->assertSame($expected, rand());
    }

    public function test_resource_guide_lists_every_frozen_passive_gain_with_exact_points(): void
    {
        $counter = $this->service(collect())->resourceGuideForDisplay($this->character(60));
        $command = $this->service(collect())->resourceGuideForDisplay($this->character(69));

        $this->assertSame('剣勢', $counter['resource_name']);
        $this->assertSame([
            ['label' => '通常攻撃HIT', 'points' => 1],
            ['label' => '直接物理攻撃を受ける', 'points' => 1],
            ['label' => '受け流し成功（さらに）', 'points' => 1],
        ], $counter['gains']);
        $this->assertSame([
            ['label' => '通常攻撃HIT', 'points' => 4],
            ['label' => '通常攻撃／現在職技の手番', 'points' => 1],
        ], $command['gains']);
    }

    public function test_mobile_modal_shows_current_variant_next_goal_and_completion_without_auto_apply(): void
    {
        view()->addLocation(dirname(__DIR__, 2).'/resources/views');
        $keys = config('job_art_official_presets.counter.tactical.variants.advanced.skills');
        $presets = $this->service($this->arts($keys, 60))->presetsForDisplay($this->character(60), 'normal');
        $launcherHtml = view('job-arts.partials.starter-presets', [
            'starterPresetCount' => count($presets),
            'slotContext' => 'normal',
            'slotContextLabel' => '通常',
        ])->render();
        $cardsHtml = view('job-arts.partials.starter-preset-cards', [
            'starterPresets' => $presets,
            'slotContext' => 'normal',
            'slotContextLabel' => '通常',
        ])->render();
        $html = $launcherHtml.$cardsHtml;

        $this->assertStringContainsString('公式プリセットから選ぶ', $html);
        $this->assertStringContainsString('（30件）', $html);
        $this->assertStringContainsString('starter-presets?slot_context=normal', $launcherHtml);
        $this->assertStringContainsString('公式プリセットを読み込んでいます', $launcherHtml);
        $this->assertStringContainsString('data-job-art-starter-preset-spinner', $launcherHtml);
        $this->assertStringContainsString('animate-spin', $launcherHtml);
        $this->assertStringContainsString('aria-busy="true"', $launcherHtml);
        $this->assertStringContainsString('data-job-art-starter-preset-header', $launcherHtml);
        $this->assertStringContainsString('data-job-art-starter-preset-footer', $launcherHtml);
        $this->assertStringNotContainsString('sticky top-0', $launcherHtml);
        $this->assertStringNotContainsString('sticky bottom-0', $launcherHtml);
        $this->assertSame(0, substr_count($launcherHtml, 'data-job-art-starter-preset='));
        $this->assertStringContainsString('全10系譜の公式プリセット', $html);
        $this->assertStringContainsString('現在の職業に関係なく、30件すべてから選べます', $html);
        $this->assertStringContainsString('data-job-art-starter-preset-modal="normal"', $html);
        $this->assertStringContainsString('fixed inset-0 z-[100] overflow-y-auto overscroll-contain', $html);
        $this->assertStringContainsString('未習得技の自動差し替えや部分適用は行いません', $html);
        $this->assertStringContainsString('現在使える構成［上級版］', $html);
        $this->assertStringContainsString('次の構成［超級版］', $html);
        $this->assertStringContainsString('完成形を見る［冠位版］', $html);
        $this->assertStringContainsString('未習得', $html);
        $this->assertStringContainsString('name="variant" value="advanced"', $html);
        $this->assertStringContainsString('name="lineage" value="counter"', $html);
        $this->assertSame(30, substr_count($html, 'data-job-art-starter-preset='));
        $this->assertSame(10, substr_count($html, 'data-job-art-starter-lineage='));
        $this->assertStringNotContainsString('自動適用', $html);
    }

    private function service(Collection $arts): JobArtV2StarterPresetService
    {
        return $this->serviceWithJobArt($arts)[0];
    }

    /** @return array{0: JobArtV2StarterPresetService, 1: JobArtService} */
    private function serviceWithJobArt(Collection $arts): array
    {
        $jobArtService = new class($arts) extends JobArtService
        {
            public array $saved = [];

            public function __construct(private readonly Collection $arts)
            {
                parent::__construct();
            }

            public function availableArts(Character $character, string $context = 'pve'): Collection
            {
                return $this->arts;
            }

            public function validateSlotConfiguration(Character $character, array $slotSkillIds, string $slotContext): void
            {
            }

            public function saveSlots(
                Character $character,
                array $slotSkillIds,
                string $slotContext = 'normal',
                string $availabilityContext = 'pve',
                array $slotPolicies = [],
                ?array $slotConditions = null,
            ): void {
                $this->saved[$slotContext] = [
                    'slots' => $slotSkillIds,
                    'policies' => $slotPolicies,
                    'conditions' => $slotConditions ?? [],
                ];
            }
        };
        $prototype = app(JobArtV2PrototypeCatalog::class);

        return [
            new JobArtV2StarterPresetService(
                $jobArtService,
                app(JobArtV2FeatureGate::class),
                $prototype,
                new JobArtV2ResourceCatalog($prototype),
                app(JobArtV2LoadoutPresenter::class),
                app(JobArtLineageCatalog::class),
                app(JobArtV2OfficialPresetCatalog::class),
                app(JobArtV2SlotConditionCatalog::class),
            ),
            $jobArtService,
        ];
    }

    private function character(int $jobId): Character
    {
        $character = new Character(['id' => 1, 'current_job_id' => $jobId]);
        $character->exists = true;

        return $character;
    }

    /** @param array<int, string> $keys */
    private function arts(array $keys, int $currentJobId): Collection
    {
        return collect($keys)
            ->unique()
            ->map(function (string $key) use ($currentJobId): Skill {
                /** @var Skill $skill */
                $skill = Skill::query()->with('jobClass')->findOrFail($this->skillId($key));
                $skill->setAttribute('job_art_origin', (int) $skill->job_id === $currentJobId ? 'current' : 'inherited');
                $skill->setAttribute('job_art_rate', 1.0);
                $skill->setAttribute('job_art_effective_cost', match ((int) $skill->learn_rank) {
                    1 => 1,
                    5 => 2,
                    9 => 3,
                });

                return $skill;
            })
            ->values();
    }

    private function seedCatalogMaster(): void
    {
        $prototype = app(JobArtV2PrototypeCatalog::class);
        $keys = collect(config('job_art_official_presets'))
            ->flatMap(fn (array $lineage): array => collect($lineage)
                ->flatMap(fn (array $preset): array => collect($preset['variants'])
                    ->flatMap(fn (array $variant): array => $variant['skills'])
                    ->all())
                ->all())
            ->unique()
            ->values();
        $jobIds = $keys->map(fn (string $key): int => (int) explode(':', $key, 2)[0])->unique();
        DB::table('job_classes')->insert($jobIds->map(fn (int $jobId): array => [
            'id' => $jobId,
            'name' => "職業{$jobId}",
            'rank' => $prototype->currentJobTier($jobId) ?? 'normal',
        ])->all());
        DB::table('skills')->insert($keys->map(function (string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key, 2));

            return [
                'id' => $this->skillId($key),
                'job_id' => $jobId,
                'name' => "戦技{$key}",
                'skill_type' => 'job_art',
                'learn_rank' => $rank,
                'art_cost' => match ($rank) { 1 => 1, 5 => 2, 9 => 3 },
                'effect_template' => 'PHYSICAL_DAMAGE',
                'limit_group' => null,
                'inherit_on_master' => true,
                'pve_enabled' => true,
                'boss_enabled' => true,
                'champ_enabled' => true,
            ];
        })->all());
    }

    private function skillId(string $key): int
    {
        [$jobId, $rank] = array_map('intval', explode(':', $key, 2));

        return ($jobId * 10) + $rank;
    }
}
