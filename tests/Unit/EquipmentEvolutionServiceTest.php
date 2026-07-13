<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\Item;
use App\Services\EquipmentEvolutionService;
use App\Services\EquipmentPermissionService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class EquipmentEvolutionServiceTest extends TestCase
{
    public function test_affix_inheritance_prefers_highest_quality_consumed_item(): void
    {
        $service = new EquipmentEvolutionService($this->createMock(EquipmentPermissionService::class));

        $plainEquipped = new CharacterItem([
            'is_equipped' => true,
            'enhance_level' => 0,
        ]);
        $plainEquipped->id = 1;
        $excellentAffixed = new CharacterItem([
            'is_equipped' => false,
            'enhance_level' => 0,
            'affix_prefix_id' => 10,
            'affix_suffix_id' => 20,
            'affix_quality' => 'excellent',
            'affix_str_bonus' => 7,
            'killer_species_key' => 'dragon',
            'killer_damage_rate' => 0.15,
        ]);
        $excellentAffixed->id = 2;
        $goodAffixed = new CharacterItem([
            'is_equipped' => true,
            'enhance_level' => 0,
            'affix_prefix_id' => 11,
            'affix_quality' => 'good',
        ]);
        $goodAffixed->id = 3;

        $source = $this->invokePrivate(
            $service,
            'selectAffixInheritanceSource',
            [new Collection([$plainEquipped, $excellentAffixed, $goodAffixed])]
        );

        $this->assertSame($excellentAffixed, $source);
    }

    public function test_affix_inheritance_payload_copies_prefix_suffix_quality_and_species_traits(): void
    {
        $service = new EquipmentEvolutionService($this->createMock(EquipmentPermissionService::class));
        $generatedAt = now()->startOfSecond();
        $source = new CharacterItem([
            'affix_prefix_id' => 10,
            'affix_suffix_id' => 20,
            'affix_quality' => 'excellent',
            'affix_hp_bonus' => 3,
            'affix_str_bonus' => 4,
            'affix_def_bonus' => 5,
            'affix_mag_bonus' => 6,
            'affix_spr_bonus' => 7,
            'affix_agi_bonus' => 8,
            'affix_luk_bonus' => 9,
            'killer_species_key' => 'dragon',
            'killer_damage_rate' => 0.15,
            'resist_species_key' => 'undead',
            'species_damage_reduction_rate' => 0.12,
            'affix_generated_at' => $generatedAt,
        ]);

        $payload = $this->invokePrivate($service, 'affixInheritancePayload', [$source]);

        $this->assertSame(10, $payload['affix_prefix_id']);
        $this->assertSame(20, $payload['affix_suffix_id']);
        $this->assertSame('excellent', $payload['affix_quality']);
        $this->assertSame(4, $payload['affix_str_bonus']);
        $this->assertSame('dragon', $payload['killer_species_key']);
        $this->assertSame(0.15, $payload['killer_damage_rate']);
        $this->assertSame('undead', $payload['resist_species_key']);
        $this->assertSame(0.12, $payload['species_damage_reduction_rate']);
        $this->assertEquals($generatedAt, $payload['affix_generated_at']);
    }

    public function test_source_option_payloads_show_affixed_display_names_and_flags(): void
    {
        $service = new EquipmentEvolutionService($this->createMock(EquipmentPermissionService::class));

        $item = new Item(['name' => '鉄の剣', 'type' => 'weapon', 'weapon_rank' => 'A', 'str_bonus' => 20]);
        $toItem = new Item(['name' => '鋼の剣']);
        $prefix = new EquipmentAffixPrefix(['name' => '鋭い', 'target_stat' => 'str']);
        $suffix = new EquipmentAffixSuffix(['name' => '竜断']);
        $source = new CharacterItem([
            'is_equipped' => true,
            'is_locked' => true,
            'enhance_level' => 2,
            'affix_prefix_id' => 10,
            'affix_suffix_id' => 20,
            'affix_quality' => 'excellent',
            'affix_str_bonus' => 4,
            'killer_species_key' => 'dragon',
            'killer_damage_rate' => 0.15,
        ]);
        $source->id = 99;
        $source->setRelation('item', $item);
        $source->setRelation('affixPrefix', $prefix);
        $source->setRelation('affixSuffix', $suffix);

        $payloads = $this->invokePrivate($service, 'sourceOptionPayloads', [new Collection([$source]), $toItem]);

        $this->assertSame(99, $payloads[0]['id']);
        $this->assertSame('[A] 鋭いI鉄の剣・竜断I【逸品】 +2', $payloads[0]['display_name']);
        $this->assertSame('鋭いI鋼の剣・竜断I【逸品】', $payloads[0]['evolved_display_name']);
        $this->assertTrue($payloads[0]['is_equipped']);
        $this->assertTrue($payloads[0]['is_locked']);
        $this->assertTrue($payloads[0]['has_affix']);
        $this->assertContains('攻撃+2', $payloads[0]['affix_lines']);
        $this->assertContains('種族が竜の敵への与ダメージ +8.1%', $payloads[0]['affix_lines']);

        $maskedPayloads = $this->invokePrivate($service, 'sourceOptionPayloads', [new Collection([$source]), $toItem, '未鑑定の剣']);
        $this->assertSame('鋭いI未鑑定の剣・竜断I【逸品】', $maskedPayloads[0]['evolved_display_name']);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
