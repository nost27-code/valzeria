<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2OfficialPresetCatalog;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2StarterPresetService;
use Tests\TestCase;

class JobArtV2OfficialPresetCatalogTest extends TestCase
{
    public function test_catalog_has_three_explicit_tier_variants_for_every_lineage_and_style(): void
    {
        $catalog = app(JobArtV2OfficialPresetCatalog::class);
        $definitions = config('job_art_official_presets');

        $this->assertSame(
            ['counter', 'eclipse', 'pierce', 'field', 'hunt', 'aim', 'guard', 'transmute', 'break', 'command'],
            $catalog->lineages(),
        );
        $this->assertEqualsCanonicalizing(
            ['counter', 'eclipse', 'pierce', 'field', 'hunt', 'aim', 'guard', 'transmute', 'break', 'command'],
            array_keys($definitions),
        );
        foreach ($definitions as $lineage => $presets) {
            $this->assertSame($catalog->styles(), array_keys($presets), $lineage);
            foreach ($presets as $style => $preset) {
                $this->assertNotSame('', trim((string) $preset['build_name']), "{$lineage}:{$style}");
                $this->assertSame($catalog->variants(), array_keys($preset['variants']), "{$lineage}:{$style}");
                foreach ($preset['variants'] as $variant => $definition) {
                    $keys = $definition['skills'];
                    $this->assertCount(5, $keys, "{$lineage}:{$style}:{$variant}");
                    $this->assertCount(5, array_unique($keys), "{$lineage}:{$style}:{$variant}");
                    $ranks = array_map(fn (string $key): int => (int) explode(':', $key, 2)[1], $keys);
                    $this->assertSame(
                        $style === JobArtV2StarterPresetService::FINISHER ? [1, 1, 1, 5, 9] : [1, 1, 5, 5, 9],
                        collect($ranks)->sort()->values()->all(),
                        "{$lineage}:{$style}:{$variant}",
                    );
                    $this->assertLessThanOrEqual(9, array_sum(array_map(
                        fn (int $rank): int => match ($rank) { 1 => 1, 5 => 2, 9 => 3 },
                        $ranks,
                    )), "{$lineage}:{$style}:{$variant}");
                }
            }
        }
    }

    public function test_every_reference_exists_in_master_and_stays_in_its_lineage_and_tier(): void
    {
        $rows = collect(json_decode(file_get_contents(database_path('data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR));
        $master = $rows->keyBy(fn (array $row): string => $row['job_id'].':'.$row['learn_rank']);
        $lineages = app(JobArtLineageCatalog::class);
        $prototype = app(JobArtV2PrototypeCatalog::class);
        $tierOrder = ['basic' => 0, 'intermediate' => 1, 'advanced' => 2, 'super' => 3, 'crown' => 4];
        $variantTier = ['advanced' => 2, 'super' => 3, 'crown' => 4];

        foreach (config('job_art_official_presets') as $lineage => $presets) {
            foreach ($presets as $style => $preset) {
                foreach ($preset['variants'] as $variant => $definition) {
                    foreach ($definition['skills'] as $key) {
                        $row = $master->get($key);
                        $this->assertIsArray($row, "Missing master {$lineage}:{$style}:{$variant}:{$key}");
                        $skill = new Skill([
                            'job_id' => $row['job_id'],
                            'learn_rank' => $row['learn_rank'],
                            'name' => $row['name'],
                            'skill_type' => 'job_art',
                        ]);
                        $this->assertSame($lineage, $lineages->forArt($skill)['lineage_key'] ?? null, $key);
                        $tier = $prototype->currentJobTier((int) $row['job_id']);
                        $this->assertLessThanOrEqual($variantTier[$variant], $tierOrder[$tier], "{$key} exceeds {$variant}");
                        $this->assertTrue((bool) $row['pve_enabled'], $key);
                        $this->assertTrue((bool) $row['boss_enabled'], $key);
                    }
                }
            }
        }
    }

    public function test_crown_variants_keep_the_human_proposed_signature_builds(): void
    {
        $expected = [
            'counter.finisher' => ['28:1', '11:1', '50:1', '50:5', '60:9'],
            'counter.cycle' => ['11:1', '13:1', '11:5', '50:5', '1:9'],
            'counter.tactical' => ['1:1', '60:1', '28:5', '60:5', '60:9'],
            'eclipse.finisher' => ['14:1', '30:1', '51:1', '51:5', '51:9'],
            'eclipse.cycle' => ['9:1', '61:1', '9:5', '30:5', '30:9'],
            'eclipse.tactical' => ['30:1', '61:1', '30:5', '61:5', '61:9'],
            'pierce.finisher' => ['2:1', '16:1', '62:1', '62:5', '62:9'],
            'pierce.cycle' => ['32:1', '52:1', '2:5', '52:5', '52:9'],
            'pierce.tactical' => ['16:1', '62:1', '32:5', '45:5', '32:9'],
            'field.finisher' => ['6:1', '23:1', '46:1', '63:5', '63:9'],
            'field.cycle' => ['53:1', '46:1', '23:5', '46:5', '53:9'],
            'field.tactical' => ['24:1', '29:1', '29:5', '53:5', '24:9'],
            'hunt.finisher' => ['54:1', '64:1', '37:1', '37:5', '64:9'],
            'hunt.cycle' => ['3:1', '34:1', '3:5', '34:5', '34:9'],
            'hunt.tactical' => ['54:1', '64:1', '54:5', '37:5', '54:9'],
            'aim.finisher' => ['22:1', '35:1', '65:1', '18:5', '65:9'],
            'aim.cycle' => ['18:1', '65:1', '22:5', '65:5', '55:9'],
            'aim.tactical' => ['4:1', '18:1', '4:5', '35:5', '35:9'],
            'guard.finisher' => ['10:1', '44:1', '56:1', '44:5', '44:9'],
            'guard.cycle' => ['7:1', '36:1', '10:5', '66:5', '36:9'],
            'guard.tactical' => ['15:1', '66:1', '15:5', '66:5', '66:9'],
            'transmute.finisher' => ['26:1', '49:1', '67:1', '26:5', '49:9'],
            'transmute.cycle' => ['25:1', '38:1', '25:5', '38:5', '47:9'],
            'transmute.tactical' => ['67:1', '49:1', '67:5', '26:5', '67:9'],
            'break.finisher' => ['68:1', '58:1', '5:1', '58:5', '58:9'],
            'break.cycle' => ['21:1', '33:1', '5:5', '68:5', '33:9'],
            'break.tactical' => ['68:1', '33:1', '33:5', '21:5', '68:9'],
            'command.finisher' => ['48:1', '59:1', '69:1', '48:5', '69:9'],
            'command.cycle' => ['27:1', '59:1', '27:5', '59:5', '59:9'],
            'command.tactical' => ['12:1', '69:1', '48:5', '69:5', '48:9'],
        ];

        foreach ($expected as $path => $skills) {
            $this->assertSame($skills, config("job_art_official_presets.{$path}.variants.crown.skills"), $path);
        }
    }
}
