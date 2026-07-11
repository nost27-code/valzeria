<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FerdiaStoryBranchConfigTest extends TestCase
{
    public function test_story_branches_and_abyss_prelude_are_declared(): void
    {
        $map = require dirname(__DIR__, 2) . '/config/ferdia_world_map.php';
        $nodesByKey = [];
        foreach ($map['nodes'] as $node) {
            $nodesByKey[$node['key']] = $node;
        }

        $requiredKeys = ['stargazer_ruin', 'aquarius_shrine', 'ordo_columns', 'white_tide_lighthouse'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $nodesByKey);
            $this->assertSame('story', $nodesByKey[$key]['route_group']);
            $this->assertNotEmpty($nodesByKey[$key]['story_record']['text'] ?? null);

            $maxPoint = (int) ($nodesByKey[$key]['max_development_point'] ?? 100);
            $recordText = implode(' ', [
                (string) ($nodesByKey[$key]['story_record']['text'] ?? ''),
                (string) ($nodesByKey[$key]['events'][$maxPoint] ?? ''),
            ]);
            $this->assertStringNotContainsString('アビス', $recordText);
            $this->assertStringNotContainsString('地下の謎の穴', $recordText);
        }

        $this->assertSame($requiredKeys, $map['story_final_unlock']['required_node_keys']);
        $this->assertSame('abyss_prelude', $map['story_final_unlock']['final_node_key']);
        $this->assertSame('all_nodes_completed', $nodesByKey['abyss_prelude']['unlock']['type']);
        $this->assertSame($requiredKeys, $nodesByKey['abyss_prelude']['unlock']['node_keys']);

        foreach (['miharashi_hill_road', 'granford_outer', 'suimon_road'] as $key) {
            $this->assertSame(150, $nodesByKey[$key]['max_development_point']);
            $this->assertArrayHasKey((int) $nodesByKey[$key]['area_id'], $map['bosses']);
        }
    }
}
