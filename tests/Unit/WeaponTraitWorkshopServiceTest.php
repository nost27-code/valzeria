<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\WeaponTraitForgeService;
use App\Services\WeaponTraitTransferService;
use App\Services\WeaponTraitWorkshopService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WeaponTraitWorkshopServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_groups_transfer_candidates_by_the_two_user_facing_entries(): void
    {
        $character = new Character();
        $forge = Mockery::mock(WeaponTraitForgeService::class);
        $transfer = Mockery::mock(WeaponTraitTransferService::class);
        $engraving = ['base_options' => [['id' => 1]], 'material_options' => [['id' => 2]], 'gold_costs' => [1 => 5_000]];
        $slayer = ['base_options' => [['id' => 3]], 'material_options' => [['id' => 4]], 'gold_costs' => [1 => 5_000]];

        $transfer->shouldReceive('candidates')
            ->once()
            ->with($character)
            ->andReturn(['engraving_transfer' => $engraving, 'slayer_transfer' => $slayer]);

        $service = new WeaponTraitWorkshopService($forge, $transfer);

        $this->assertSame(['engraving' => $engraving, 'slayer' => $slayer], $service->candidates($character));
    }

    public function test_it_dispatches_selected_forge_and_transfer_actions(): void
    {
        $character = new Character();
        $forge = Mockery::mock(WeaponTraitForgeService::class);
        $transfer = Mockery::mock(WeaponTraitTransferService::class);
        $forgeResult = ['message' => '鍛錬完了', 'base_character_item_id' => 10, 'gold_cost' => 20_000];
        $transferResult = ['message' => '移し完了', 'base_character_item_id' => 10, 'gold_cost' => 30_000];

        $forge->shouldReceive('forge')
            ->once()
            ->with($character, 'engraving_forge', 10, 20)
            ->andReturn($forgeResult);
        $transfer->shouldReceive('transfer')
            ->once()
            ->with($character, 'slayer_transfer', 10, 30)
            ->andReturn($transferResult);

        $service = new WeaponTraitWorkshopService($forge, $transfer);

        $this->assertSame($forgeResult, $service->process($character, 'engraving', 'forge', 10, 20));
        $this->assertSame($transferResult, $service->process($character, 'slayer', 'transfer', 10, 30));
    }

    public function test_it_dispatches_the_optional_dual_action_to_dual_forge(): void
    {
        $character = new Character();
        $forge = Mockery::mock(WeaponTraitForgeService::class);
        $transfer = Mockery::mock(WeaponTraitTransferService::class);
        $result = ['message' => 'まとめて鍛錬完了', 'base_character_item_id' => 10, 'gold_cost' => 80_000];

        $forge->shouldReceive('forge')
            ->once()
            ->with($character, 'dual_forge', 10, 20)
            ->andReturn($result);

        $service = new WeaponTraitWorkshopService($forge, $transfer);

        $this->assertSame($result, $service->process($character, 'engraving', 'dual', 10, 20));
    }

    public function test_it_rejects_invalid_entry_or_action(): void
    {
        $service = new WeaponTraitWorkshopService(
            Mockery::mock(WeaponTraitForgeService::class),
            Mockery::mock(WeaponTraitTransferService::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('鍛える特性が不正です。');

        $service->process(new Character(), 'invalid', 'forge', 10, 20);
    }
}
