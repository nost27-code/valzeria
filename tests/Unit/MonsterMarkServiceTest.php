<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Enemy;
use App\Models\MonsterMark;
use App\Services\MonsterMarkService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class MonsterMarkServiceTest extends TestCase
{
    public function test_same_area_and_enemy_name_marks_are_merged_even_when_enemy_ids_differ(): void
    {
        $service = new MonsterMarkService();
        $area = new Area();
        $area->id = 51;

        $ownedEnemy = new Enemy(['area_id' => 51, 'name' => '呪い騎士']);
        $ownedEnemy->id = 5101;
        $unownedEnemy = new Enemy(['area_id' => 51, 'name' => '呪い騎士']);
        $unownedEnemy->id = 5102;

        $ownedMark = new MonsterMark(['mark_name' => '呪い騎士の印', 'bonus_per_level' => 3, 'max_level' => 4]);
        $ownedMark->id = 101;
        $unownedMark = new MonsterMark(['mark_name' => '呪い騎士の印', 'bonus_per_level' => 3, 'max_level' => 4]);
        $unownedMark->id = 102;

        $entries = new Collection([
            $this->entry($ownedMark, $ownedEnemy, $area, 9),
            $this->entry($unownedMark, $unownedEnemy, $area, 0),
        ]);

        $merged = $this->invokePrivate($service, 'deduplicateCollectionEntries', [$entries]);

        $this->assertCount(1, $merged);
        $this->assertSame(9, $merged->first()['quantity']);
        $this->assertSame(3, $merged->first()['unlocked_level']);
        $this->assertTrue($merged->first()['is_discovered']);

        $ownedMark->setRelation('enemy', $ownedEnemy);
        $unownedMark->setRelation('enemy', $unownedEnemy);
        $this->assertSame(
            $this->invokePrivate($service, 'markSignature', [$ownedMark]),
            $this->invokePrivate($service, 'markSignature', [$unownedMark]),
        );
    }

    private function entry(MonsterMark $mark, Enemy $enemy, Area $area, int $quantity): array
    {
        return [
            'mark' => $mark,
            'enemy' => $enemy,
            'area' => $area,
            'quantity' => $quantity,
            'unlocked_level' => 0,
            'next_required' => null,
            'max_level' => 4,
            'bonus_label' => 'HP',
            'total_bonus' => 0,
            'progress_percent' => 0,
            'is_discovered' => $quantity > 0,
            'is_area_discovered' => true,
            'is_complete' => false,
        ];
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
