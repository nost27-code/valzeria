<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<string, int|string|bool>> */
    private const TITLES = [
        122 => [
            'category' => 'equipment',
            'rarity' => 'common',
            'name' => '鍛冶の心得',
            'description' => '強化値+10以上の装備を所持する',
            'hint' => '装備を鍛え、強化値+10を目指そう。',
            'unlock_type' => 'equipment_enhance_level',
            'target_type' => 'enhance_level',
            'target_id' => '10',
            'source_master' => '所持装備/強化値',
            'display_order' => 121,
            'is_hidden' => true,
        ],
        123 => [
            'category' => 'equipment',
            'rarity' => 'epic',
            'name' => '百錬の使い手',
            'description' => '強化値+20以上の装備を所持する',
            'hint' => 'さらに装備を鍛え、強化値+20へ到達しよう。',
            'unlock_type' => 'equipment_enhance_level',
            'target_type' => 'enhance_level',
            'target_id' => '20',
            'source_master' => '所持装備/強化値',
            'display_order' => 122,
            'is_hidden' => true,
        ],
        124 => [
            'category' => 'equipment',
            'rarity' => 'legendary',
            'name' => '極鍛の到達者',
            'description' => '強化値+30の装備を所持する',
            'hint' => '最高強化値+30まで装備を鍛え上げよう。',
            'unlock_type' => 'equipment_enhance_level',
            'target_type' => 'enhance_level',
            'target_id' => '30',
            'source_master' => '所持装備/強化値',
            'display_order' => 123,
            'is_hidden' => true,
        ],
        125 => [
            'category' => 'equipment',
            'rarity' => 'rare',
            'name' => '良品を見抜く者',
            'description' => '良品以上の装備を所持する',
            'hint' => '冒険や鍛冶を通じて、良品以上の装備を手に入れよう。',
            'unlock_type' => 'equipment_quality',
            'target_type' => 'quality',
            'target_id' => 'good',
            'source_master' => '所持装備/品質',
            'display_order' => 124,
            'is_hidden' => true,
        ],
        126 => [
            'category' => 'equipment',
            'rarity' => 'legendary',
            'name' => '逸品を携えし者',
            'description' => '逸品の装備を所持する',
            'hint' => '希少な逸品の装備を手に入れよう。',
            'unlock_type' => 'equipment_quality',
            'target_type' => 'quality',
            'target_id' => 'excellent',
            'source_master' => '所持装備/品質',
            'display_order' => 125,
            'is_hidden' => true,
        ],
        127 => [
            'category' => 'equipment',
            'rarity' => 'rare',
            'name' => '魔物狩りの刃',
            'description' => '種族特攻を持つ武器を所持する',
            'hint' => '特定種族への特攻を宿した武器を手に入れよう。',
            'unlock_type' => 'weapon_species_killer',
            'target_type' => 'killer',
            'target_id' => 'any',
            'source_master' => '所持武器/種族特攻',
            'display_order' => 126,
            'is_hidden' => true,
        ],
        128 => [
            'category' => 'equipment',
            'rarity' => 'rare',
            'name' => '堅守を纏う者',
            'description' => '種族耐性を持つ防具を所持する',
            'hint' => '特定種族への耐性を宿した防具を手に入れよう。',
            'unlock_type' => 'armor_species_resist',
            'target_type' => 'resist',
            'target_id' => 'any',
            'source_master' => '所持防具/種族耐性',
            'display_order' => 127,
            'is_hidden' => true,
        ],
        129 => [
            'category' => 'equipment',
            'rarity' => 'epic',
            'name' => '特性を磨く者',
            'description' => '段階III以上の銘・特攻・耐性を持つ装備を所持する',
            'hint' => '装備の銘・特攻・耐性のいずれかを段階IIIまで磨こう。',
            'unlock_type' => 'equipment_trait_level',
            'target_type' => 'trait_level',
            'target_id' => '3',
            'source_master' => '所持装備/特性段階',
            'display_order' => 128,
            'is_hidden' => true,
        ],
        130 => [
            'category' => 'equipment',
            'rarity' => 'mythic',
            'name' => '特性を極めし者',
            'description' => '段階Vの銘・特攻・耐性を持つ装備を所持する',
            'hint' => '装備の銘・特攻・耐性のいずれかを最高段階Vまで磨こう。',
            'unlock_type' => 'equipment_trait_level',
            'target_type' => 'trait_level',
            'target_id' => '5',
            'source_master' => '所持装備/特性段階',
            'display_order' => 129,
            'is_hidden' => true,
        ],
        131 => [
            'category' => 'equipment',
            'rarity' => 'mythic',
            'name' => '神工の担い手',
            'description' => '強化値+30・逸品・段階Vの特性を備えた同一装備を所持する',
            'hint' => '一つの装備に最高強化、逸品、最高段階の特性をそろえよう。',
            'unlock_type' => 'equipment_masterpiece',
            'target_type' => 'masterpiece',
            'target_id' => '30:excellent:5',
            'source_master' => '所持装備/鍛冶到達点',
            'display_order' => 130,
            'is_hidden' => true,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        $this->assertTitleIdsAreAvailable();
        $this->assertNaturalKeysAreAvailable();

        $now = now();
        $rows = [];
        foreach (self::TITLES as $id => $title) {
            $rows[] = [
                'id' => $id,
                ...$title,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('titles')->upsert(
            $rows,
            ['id'],
            [
                'category',
                'rarity',
                'name',
                'description',
                'hint',
                'unlock_type',
                'target_type',
                'target_id',
                'source_master',
                'display_order',
                'is_hidden',
                'updated_at',
            ]
        );
    }

    public function down(): void
    {
        // Forward-only: deleting title masters would cascade into player-owned character_titles rows.
    }

    private function assertTitleIdsAreAvailable(): void
    {
        $existing = DB::table('titles')
            ->whereIn('id', array_keys(self::TITLES))
            ->get()
            ->keyBy('id');

        foreach (self::TITLES as $id => $title) {
            $row = $existing->get($id);
            if (! $row) {
                continue;
            }

            foreach ($title as $column => $expected) {
                $actual = $row->{$column};
                $actual = match (true) {
                    is_bool($expected) => (bool) $actual,
                    is_int($expected) => (int) $actual,
                    default => (string) $actual,
                };

                if ($actual !== $expected) {
                    throw new RuntimeException(
                        "Title ID {$id} already exists with a different {$column} value."
                    );
                }
            }
        }
    }

    private function assertNaturalKeysAreAvailable(): void
    {
        foreach (self::TITLES as $id => $title) {
            $existingIds = DB::table('titles')
                ->where('unlock_type', $title['unlock_type'])
                ->where('target_type', $title['target_type'])
                ->where('target_id', $title['target_id'])
                ->pluck('id')
                ->map(static fn ($existingId): int => (int) $existingId)
                ->unique()
                ->values()
                ->all();
            $unexpectedIds = array_values(array_diff($existingIds, [$id]));

            if ($unexpectedIds !== []) {
                throw new RuntimeException(
                    "Title condition {$title['unlock_type']}/{$title['target_type']}/{$title['target_id']} also uses unexpected IDs ".implode(', ', $unexpectedIds).'.'
                );
            }
        }
    }
};
