<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\PublicLogService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipmentDropPublicLogTest extends TestCase
{
    #[DataProvider('equipmentDrops')]
    public function test_equipment_drop_logging_preserves_normal_exploration_rules(array $drop, ?string $message, int $importance = 3): void
    {
        $character = new Character(['name' => '探索者']);
        $service = $this->getMockBuilder(PublicLogService::class)
            ->onlyMethods(['addLog'])
            ->getMock();

        if ($message === null) {
            $service->expects($this->never())->method('addLog');
        } else {
            $service->expects($this->once())->method('addLog')
                ->with('drop', $message, $character, $importance);
        }

        $service->addEquipmentDropLogs($character, [$drop + ['item_name' => '試験装備']]);
    }

    public static function equipmentDrops(): array
    {
        $excellent = '【逸品】探索者さんが「試験装備」を手に入れました！';
        $epic = '【獲得】探索者さんがEPICランク装備「試験装備」を手に入れました！';

        return [
            'excellent A' => [['rank' => 'A', 'rarity' => 'common', 'affix_quality' => 'excellent'], $excellent],
            'excellent EPIC is announced once' => [['rank' => 'EPIC', 'rarity' => 'epic', 'affix_quality' => 'excellent'], $excellent],
            'SSS' => [['rank' => 'SSS', 'rarity' => 'common'], '【獲得】探索者さんがSSSランク装備「試験装備」を手に入れました！'],
            'lowercase EPIC' => [['rank' => 'epic', 'rarity' => 'common'], $epic],
            'legacy rank' => [['rank' => 'LEGEND', 'rarity' => 'common'], $epic],
            'legacy rarity' => [['rank' => 'A', 'rarity' => 'legend'], $epic],
            'rarity fallback' => [['rarity' => 'epic'], $epic],
            'legacy rare fallback' => [['rarity' => 'rare'], '【獲得】探索者さんがRAREランク装備「試験装備」を手に入れました！', 2],
            'legacy rare with rank' => [['rank' => 'A', 'rarity' => 'rare'], '【獲得】探索者さんがAランク装備「試験装備」を手に入れました！', 2],
            'normal A' => [['rank' => 'A', 'rarity' => 'common'], null],
            'good A' => [['rank' => 'A', 'rarity' => 'common', 'affix_quality' => 'good'], null],
            'normal S' => [['rank' => 'S', 'rarity' => 'common'], null],
            'normal SS' => [['rank' => 'SS', 'rarity' => 'common'], null],
            'missing rank and rarity' => [[], null],
        ];
    }

    public function test_no_equipment_drops_do_not_create_a_public_log(): void
    {
        $service = $this->getMockBuilder(PublicLogService::class)
            ->onlyMethods(['addLog'])
            ->getMock();
        $service->expects($this->never())->method('addLog');

        $service->addEquipmentDropLogs(new Character(['name' => '探索者']), []);
    }
}
