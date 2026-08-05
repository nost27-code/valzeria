<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterEnemyDiscovery;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\MaterialDrop;
use App\Models\Material;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnemyBookService
{
    public function bookFor(Character $character): array
    {
        $discoveries = $this->discoveriesFor($character);
        $enemies = Enemy::query()
            ->join('areas', 'areas.id', '=', 'enemies.area_id')
            ->where('areas.is_published', true)
            ->with('area')
            ->select('enemies.*')
            ->orderBy('areas.city_id')
            ->orderBy('areas.unlock_order')
            ->orderBy('areas.sort_order')
            ->orderBy('enemies.sort_order')
            ->orderBy('enemies.id')
            ->get();

        $entries = $enemies->values()->map(function (Enemy $enemy, int $index) use ($discoveries): array {
            $discovery = $discoveries->get((int) $enemy->id);
            $state = $this->stateFor($discovery);
            $imagePath = $state !== 'undiscovered' ? $this->imagePathFor($enemy) : null;

            return [
                'id' => (int) $enemy->id,
                'number' => $index + 1,
                'state' => $state,
                'state_label' => match ($state) {
                    'defeated' => '討伐済み',
                    'encountered' => '発見済み・未討伐',
                    default => '未発見',
                },
                'name' => $state === 'undiscovered' ? '？？？' : (string) $enemy->name,
                'search_name' => $state === 'undiscovered' ? '' : mb_strtolower((string) $enemy->name),
                'image_url' => $imagePath ? asset($imagePath) : null,
                'is_boss' => (bool) $enemy->is_boss,
            ];
        })->all();

        $initialEntry = collect($entries)->firstWhere('state', 'defeated')
            ?? collect($entries)->firstWhere('state', 'encountered')
            ?? ($entries[0] ?? null);

        return [
            'entries' => $entries,
            'initial_enemy_id' => $initialEntry['id'] ?? null,
            'summary' => [
                'total_count' => count($entries),
                'encountered_count' => collect($entries)->whereIn('state', ['encountered', 'defeated'])->count(),
                'defeated_count' => collect($entries)->where('state', 'defeated')->count(),
            ],
        ];
    }

    public function detailFor(Character $character, Enemy $enemy): array
    {
        $discovery = Schema::hasTable(EnemyDiscoveryService::TABLE)
            ? CharacterEnemyDiscovery::query()
                ->where('character_id', $character->id)
                ->where('enemy_id', $enemy->id)
                ->first()
            : null;
        $state = $this->stateFor($discovery);
        $imagePath = $state !== 'undiscovered' ? $this->imagePathFor($enemy) : null;

        $base = [
            'id' => (int) $enemy->id,
            'state' => $state,
            'name' => $state === 'undiscovered' ? '？？？' : (string) $enemy->name,
            'image_url' => $imagePath ? asset($imagePath) : null,
            'defeat_count' => (int) ($discovery?->defeat_count ?? 0),
        ];

        if ($state === 'undiscovered') {
            return $base + [
                'message' => 'まだ姿を見たことがない魔物です。探索を続けて発見しましょう。',
                'details_unlocked' => false,
            ];
        }

        if ($state === 'encountered') {
            return $base + [
                'message' => '姿は図鑑に記録されました。討伐すると詳しい情報が明らかになります。',
                'details_unlocked' => false,
            ];
        }

        $enemy->loadMissing('area');
        $areaBackgroundPath = $enemy->area
            ? app(DungeonCardVisualService::class)->cardBackgroundPath($enemy->area, $this->areaCardOrder($enemy->area))
            : null;

        return $base + [
            'message' => '討伐記録から詳しい情報を確認できます。',
            'details_unlocked' => true,
            'area_name' => (string) ($enemy->area?->name ?? '不明'),
            'area_card_background_url' => $areaBackgroundPath ? asset('images/' . $areaBackgroundPath) : null,
            'role' => (bool) $enemy->is_boss ? 'ボス' : ((string) ($enemy->role ?: '通常敵')),
            'type_name' => (string) ($enemy->type_name ?: '標準型'),
            'species' => $this->speciesLabel($enemy),
            'element' => (string) ($enemy->element ?: '不明'),
            'level' => (int) ($enemy->enemy_level ?: $enemy->level),
            'stats' => [
                ['label' => 'HP', 'value' => (int) $enemy->max_hp],
                ['label' => '攻撃', 'value' => (int) $enemy->str],
                ['label' => '防御', 'value' => (int) $enemy->def],
                ['label' => '魔力', 'value' => (int) $enemy->mag],
                ['label' => '精神', 'value' => (int) ($enemy->spr ?? $enemy->def)],
                ['label' => '敏捷', 'value' => (int) $enemy->agi],
                ['label' => '運', 'value' => (int) $enemy->luk],
            ],
            'drops' => $this->dropsFor($enemy),
            'actions' => $enemy->actions()->pluck('name')->filter()->unique()->values()->all(),
            'action_pattern' => (string) ($enemy->action_pattern ?: '詳しい行動傾向は記録されていません。'),
        ];
    }

    private function discoveriesFor(Character $character): Collection
    {
        if (! Schema::hasTable(EnemyDiscoveryService::TABLE)) {
            return collect();
        }

        return CharacterEnemyDiscovery::query()
            ->where('character_id', $character->id)
            ->get()
            ->keyBy(fn (CharacterEnemyDiscovery $discovery): int => (int) $discovery->enemy_id);
    }

    private function stateFor(?CharacterEnemyDiscovery $discovery): string
    {
        if ($discovery?->first_defeated_at !== null) {
            return 'defeated';
        }

        return $discovery ? 'encountered' : 'undiscovered';
    }

    private function imagePathFor(Enemy $enemy): ?string
    {
        $path = config('enemy_images')[(string) $enemy->name] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function areaCardOrder($area): int
    {
        return Enemy::query()
            ->join('areas', 'areas.id', '=', 'enemies.area_id')
            ->where('areas.city_id', $area->city_id)
            ->where('areas.is_published', true)
            ->select('areas.id')
            ->distinct()
            ->orderBy('areas.sort_order')
            ->pluck('areas.id')
            ->search($area->id) + 1;
    }

    private function speciesLabel(Enemy $enemy): string
    {
        $key = (string) ($enemy->species_key ?: '');
        $labels = (array) config('enemy_species.labels', []);

        return (string) ($labels[$key] ?? '種族不明');
    }

    private function dropsFor(Enemy $enemy): array
    {
        $itemDrops = EnemyDrop::query()
            ->where('enemy_id', $enemy->id)
            ->where('is_active', true)
            ->where('drop_rate', '>', 0)
            ->with('item')
            ->get()
            ->map(fn (EnemyDrop $drop): ?array => $drop->item ? [
                'key' => 'item-' . $drop->item->id,
                'name' => (string) $drop->item->name,
                'image_url' => $drop->item->iconImagePath() ? asset($drop->item->iconImagePath()) : null,
                'item_book_url' => null,
            ] : null);

        $materialDrops = MaterialDrop::query()
            ->where('enemy_id', $enemy->id)
            ->where('is_active', true)
            ->where('drop_rate', '>', 0)
            ->with('material')
            ->get()
            ->map(fn (MaterialDrop $drop): ?array => $drop->material ? [
                'key' => 'material-' . $drop->material->id,
                'name' => (string) $drop->material->name,
                'image_url' => $drop->material->iconImagePath() ? asset($drop->material->iconImagePath()) : null,
                'item_book_url' => $this->itemBookUrlFor($drop->material),
            ] : null);

        return $itemDrops
            ->concat($materialDrops)
            ->filter()
            ->unique('key')
            ->values()
            ->all();
    }

    private function itemBookUrlFor(Material $material): ?string
    {
        $anchorId = ItemBookService::materialAnchorId($material->material_code);

        return $anchorId ? route('item-book.index') . '#' . $anchorId : null;
    }
}
