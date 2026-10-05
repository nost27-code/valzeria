<?php

namespace Tests\Unit;

use App\Models\PlayerRelic;
use App\Services\NamelessRelicCatalog;
use Tests\TestCase;

class NamelessRelicImagesTest extends TestCase
{
    public function test_every_relic_definition_has_its_own_game_image_for_all_ranks(): void
    {
        $catalog = app(NamelessRelicCatalog::class);
        $this->assertCount(87, $catalog->all());
        $paths = [];
        foreach ($catalog->all() as $key => $effect) {
            $path = $catalog->imagePath($key);
            $this->assertFileExists(public_path($path), $effect['name']);
            $size = getimagesize(public_path($path));
            $this->assertSame([160, 160, 'image/webp'], [$size[0], $size[1], $size['mime']], $key);
            foreach ([1, 9] as $rank) {
                $this->assertSame($path, (new PlayerRelic(['effect_key' => $key, 'rank' => $rank]))->imagePath());
            }
            $paths[] = $path;
        }
        $this->assertCount(87, array_unique($paths));
        $this->assertNull($catalog->imagePath('missing_relic'));
        $this->assertNull($catalog->imagePath('../icon/icon_151'));
    }

    public function test_reward_images_keep_legacy_name_only_snapshots_renderable(): void
    {
        $html = view('nameless-workshop.explore-result', ['namelessRuins' => [
            'zone_name' => '沈水の水路', 'depth' => 1, 'advanced' => false,
            'relic_drops' => [
                ['name' => '生命の遺物 I', 'summary' => 'HP+1%', 'effect_key' => 'stat_hp'],
                ['name' => '古い保存結果の遺物', 'summary' => '保存された効果'],
            ],
        ]])->render();
        $this->assertStringContainsString('src="'.asset('images/relics/stat_hp.webp').'"', $html);
        $this->assertStringContainsString('古い保存結果の遺物', $html);
        $this->assertStringContainsString('保存された効果', $html);
        $this->assertSame(1, substr_count($html, '<img'));
    }
}
