<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DropWeaponEvolutionSeeder extends Seeder
{
    private const DATA_PATH = 'database/data/drop_weapon_evolution_chains.json';

    private const SCALE_VERSION = 2;

    private const STAT_COLUMNS = [
        'hp_bonus',
        'mp_bonus',
        'str_bonus',
        'def_bonus',
        'agi_bonus',
        'mag_bonus',
        'spr_bonus',
        'luk_bonus',
    ];

    private const ENHANCE_CAPS = [
        'G' => 10,
        'F' => 10,
        'E' => 10,
        'D' => 15,
        'C' => 15,
        'B' => 15,
        'A' => 20,
        'S' => 25,
        'SS' => 30,
        'SSS' => 30,
        'EPIC' => 30,
    ];

    private ?array $sourceWeaponRows = null;

    public function run(): void
    {
        if (
            !Schema::hasTable('items')
            || !Schema::hasTable('weapon_ranks')
            || !Schema::hasTable('weapon_evolution_recipes')
            || !Schema::hasTable('weapon_evolution_recipe_ingredients')
        ) {
            $this->command?->warn('固有ドロップ武器進化に必要なテーブルがないため、投入をスキップしました。');
            return;
        }

        $master = $this->loadMaster();
        $chains = array_values($master['chains'] ?? []);
        $rankOrder = array_values($master['rank_order'] ?? []);

        if ($chains === [] || $rankOrder === []) {
            throw new RuntimeException('drop_weapon_evolution_chains.json に進化系統またはランク順がありません。');
        }

        $sourceExternalIds = array_column($chains, 'source_external_item_id');
        $existingSourceExternalIds = DB::table('items')
            ->whereIn('external_item_id', $sourceExternalIds)
            ->pluck('external_item_id')
            ->all();
        $existingSourceCount = count($existingSourceExternalIds);

        // Fresh migrate runs before DatabaseSeeder. The dedicated seeder runs again
        // immediately after DropEquipmentAdditionsSeeder, when all source items exist.
        if ($existingSourceCount === 0) {
            $this->command?->warn('固有ドロップ元武器が未投入のため、進化系統の投入を後続Seederへ委ねました。');
            return;
        }

        if ($existingSourceCount !== count($sourceExternalIds)) {
            $this->command?->warn(
                '固有ドロップ元武器が一部未投入のため、存在する'
                . $existingSourceCount . '系統だけを更新し、残りは後続Seederへ委ねました。'
            );
            $chains = array_values(array_filter(
                $chains,
                static fn (array $chain): bool => in_array(
                    (string) ($chain['source_external_item_id'] ?? ''),
                    $existingSourceExternalIds,
                    true,
                ),
            ));
        }

        $rankRows = DB::table('weapon_ranks')
            ->whereIn('rank', $rankOrder)
            ->get()
            ->keyBy('rank');

        foreach ($rankOrder as $rank) {
            if (!$rankRows->has($rank)) {
                throw new RuntimeException("武器ランク {$rank} が weapon_ranks にありません。");
            }
        }

        DB::transaction(function () use ($chains, $rankOrder, $rankRows): void {
            foreach ($chains as $chainIndex => $chain) {
                $this->importChain($chain, $chainIndex, $rankOrder, $rankRows);
            }
        });

        $this->command?->info('固有ドロップ武器' . count($chains) . '系統の一本道進化を更新しました。');
    }

    private function loadMaster(): array
    {
        $path = base_path(self::DATA_PATH);
        if (!is_file($path)) {
            throw new RuntimeException(self::DATA_PATH . ' が見つかりません。');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function importChain(array $chain, int $chainIndex, array $rankOrder, $rankRows): void
    {
        $sourceExternalId = (string) ($chain['source_external_item_id'] ?? '');
        $sourceRank = strtoupper((string) ($chain['source_rank'] ?? ''));
        $familyKey = strtoupper((string) ($chain['key'] ?? ''));
        $familyId = 'DROP_' . $familyKey;
        $familyName = (string) ($chain['family_name'] ?? '');
        $templateFamilyId = strtoupper((string) ($chain['template_family_id'] ?? ''));
        $path = strtolower((string) ($chain['path'] ?? ''));
        $names = $chain['names'] ?? [];

        if (
            $sourceExternalId === ''
            || $familyKey === ''
            || $familyName === ''
            || $templateFamilyId === ''
            || !in_array($path, ['holy', 'dark', 'gale'], true)
        ) {
            throw new RuntimeException('固有ドロップ武器進化マスタの必須項目が不足しています。');
        }

        $source = DB::table('items')->where('external_item_id', $sourceExternalId)->first();
        if (!$source) {
            throw new RuntimeException("進化元武器 {$sourceExternalId} がありません。");
        }

        $source = $this->normalizeSourceWeapon($source);
        $sourceRankIndex = array_search($sourceRank, $rankOrder, true);
        if ($sourceRankIndex === false) {
            throw new RuntimeException("進化元ランク {$sourceRank} がランク順にありません。");
        }

        $targetRanks = array_slice($rankOrder, $sourceRankIndex + 1);
        if (array_keys($names) !== $targetRanks) {
            throw new RuntimeException("{$sourceExternalId} の進化先名称がランク順と一致しません。");
        }

        $rankExternalIds = [$sourceRank => $sourceExternalId];
        foreach ($targetRanks as $rank) {
            $rankExternalIds[$rank] = $this->evolvedExternalId($familyKey, $rank);
        }

        $this->updateSourceWeapon(
            $source,
            $sourceRank,
            $rankRows,
            $familyId,
            $familyName,
            $rankExternalIds[$targetRanks[0]]
        );

        foreach ($targetRanks as $rank) {
            $rankIndex = array_search($rank, $rankOrder, true);
            $nextRank = $rankOrder[$rankIndex + 1] ?? null;

            $this->upsertEvolvedWeapon(
                source: $source,
                chainIndex: $chainIndex,
                sourceRank: $sourceRank,
                targetRank: $rank,
                targetName: (string) $names[$rank],
                externalId: $rankExternalIds[$rank],
                nextExternalId: $nextRank !== null ? $rankExternalIds[$nextRank] : null,
                familyId: $familyId,
                familyName: $familyName,
                templateFamilyId: $templateFamilyId,
                rankRow: $rankRows[$rank],
                sourceRankRow: $rankRows[$sourceRank]
            );
        }

        $fromRanks = array_slice($rankOrder, $sourceRankIndex, -1);
        foreach ($fromRanks as $fromRank) {
            $fromRankIndex = array_search($fromRank, $rankOrder, true);
            $toRank = $rankOrder[$fromRankIndex + 1];

            $this->upsertRecipe(
                familyKey: $familyKey,
                familyId: $familyId,
                familyName: $familyName,
                templateFamilyId: $templateFamilyId,
                path: $path,
                fromRank: $fromRank,
                toRank: $toRank,
                fromExternalId: $rankExternalIds[$fromRank],
                fromName: $fromRank === $sourceRank ? (string) $source->name : (string) $names[$fromRank],
                toExternalId: $rankExternalIds[$toRank],
                toName: (string) $names[$toRank]
            );
        }
    }

    private function normalizeSourceWeapon(object $source): object
    {
        $payload = [];
        $sourceRow = $this->sourceWeaponRow((string) $source->external_item_id);
        foreach (self::STAT_COLUMNS as $column) {
            $factor = $column === 'hp_bonus' ? 4 : 8;
            $payload[$column] = (int) ($sourceRow[$column] ?? 0) * $factor;
        }

        if (Schema::hasColumn('items', 'str_bonus_base')) {
            $payload['str_bonus_base'] = $payload['str_bonus'];
        }
        if (Schema::hasColumn('items', 'mag_bonus_base')) {
            $payload['mag_bonus_base'] = $payload['mag_bonus'];
        }

        $payload['weapon_offense_scale_version'] = self::SCALE_VERSION;
        $payload['updated_at'] = now();

        $isAlreadyNormalized = (int) ($source->weapon_offense_scale_version ?? 0) >= self::SCALE_VERSION;
        foreach (self::STAT_COLUMNS as $column) {
            $isAlreadyNormalized = $isAlreadyNormalized
                && (int) ($source->{$column} ?? 0) === $payload[$column];
        }
        if ($isAlreadyNormalized) {
            return $source;
        }

        DB::table('items')->where('id', $source->id)->update($payload);

        return DB::table('items')->where('id', $source->id)->first();
    }

    private function sourceWeaponRow(string $externalItemId): array
    {
        if ($this->sourceWeaponRows === null) {
            $path = base_path('database/data/drop_equipment_additions.json');
            if (!is_file($path)) {
                throw new RuntimeException('database/data/drop_equipment_additions.json が見つかりません。');
            }

            $sourceData = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->sourceWeaponRows = [];
            foreach ($sourceData['items'] ?? [] as $row) {
                $externalId = (string) ($row['external_item_id'] ?? '');
                if ($externalId !== '') {
                    $this->sourceWeaponRows[$externalId] = $row;
                }
            }
        }

        if (!isset($this->sourceWeaponRows[$externalItemId])) {
            throw new RuntimeException("元武器 {$externalItemId} がドロップ装備マスタにありません。");
        }

        return $this->sourceWeaponRows[$externalItemId];
    }

    private function updateSourceWeapon(
        object $source,
        string $sourceRank,
        $rankRows,
        string $familyId,
        string $familyName,
        string $nextExternalId
    ): void {
        $description = (string) ($source->description ?? '');
        $description = str_replace(
            ['進化不可', '+3強化可'],
            ['専用系統へ進化可', 'ランクに応じて強化可'],
            $description
        );

        $payload = [
            'description' => $description,
            'weapon_family_id' => $familyId,
            'weapon_family_name' => $familyName,
            'weapon_rank' => $sourceRank,
            'weapon_rank_sort' => (int) $rankRows[$sourceRank]->rank_sort,
            'weapon_rank_multiplier' => (float) $rankRows[$sourceRank]->multiplier,
            'evolution_stage' => 0,
            'next_item_external_id' => $nextExternalId,
            'is_evolution_enabled' => true,
            'affix_enabled' => true,
            'max_enhance' => self::ENHANCE_CAPS[$sourceRank],
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('items', 'is_evolvable')) {
            $payload['is_evolvable'] = true;
        }

        // Source drops have their real rank so rank-dependent rules (affixes,
        // market appraisal, display) work. DropService excludes DROP_WPN_* from
        // its generic rank-based pool; they remain available only through enemy_drops.
        DB::table('items')->where('id', $source->id)->update($payload);
    }

    private function upsertEvolvedWeapon(
        object $source,
        int $chainIndex,
        string $sourceRank,
        string $targetRank,
        string $targetName,
        string $externalId,
        ?string $nextExternalId,
        string $familyId,
        string $familyName,
        string $templateFamilyId,
        object $rankRow,
        object $sourceRankRow
    ): void {
        $nameCollision = DB::table('items')
            ->where('name', $targetName)
            ->where('external_item_id', '!=', $externalId)
            ->exists();
        if ($nameCollision) {
            throw new RuntimeException("進化武器名「{$targetName}」が既存アイテムと重複しています。");
        }

        $payload = [
            'name' => $targetName,
            'type' => 'weapon',
            'description' => "{$familyName}の{$targetRank}ランク進化武器。固有ドロップから一本道で進化する。",
            'rarity' => $targetRank,
            'price' => 0,
            'sell_price' => $this->standardSellPrice($templateFamilyId, $targetRank),
            'required_level' => (int) ($source->required_level ?? 1),
            'is_shop_item' => false,
            'is_active' => true,
            'sort_order' => 20000 + ($chainIndex * 100) + (int) $rankRow->rank_sort,
            'unlock_city_id' => $source->unlock_city_id,
            'sub_type' => $source->sub_type,
            'element' => $source->element,
            'weapon_category' => $source->weapon_category,
            'weapon_hand_type' => $source->weapon_hand_type,
            'weapon_role' => $source->weapon_role,
            'weapon_family_id' => $familyId,
            'weapon_family_name' => $familyName,
            'weapon_rank' => $targetRank,
            'weapon_rank_sort' => (int) $rankRow->rank_sort,
            'weapon_rank_multiplier' => (float) $rankRow->multiplier,
            'evolution_stage' => (int) $rankRow->rank_sort - (int) $sourceRankRow->rank_sort,
            'next_item_external_id' => $nextExternalId,
            'is_evolution_enabled' => $nextExternalId !== null,
            'is_drop_enabled' => false,
            'is_supply_enabled' => false,
            'max_enhance' => self::ENHANCE_CAPS[$targetRank],
            'affix_enabled' => true,
            'weapon_offense_scale_version' => self::SCALE_VERSION,
            'updated_at' => now(),
        ];

        foreach (self::STAT_COLUMNS as $column) {
            $payload[$column] = $this->scaledStat(
                (int) ($source->{$column} ?? 0),
                (float) $sourceRankRow->multiplier,
                (float) $rankRow->multiplier,
                $column
            );
        }

        if (Schema::hasColumn('items', 'str_bonus_base')) {
            $payload['str_bonus_base'] = $payload['str_bonus'];
        }
        if (Schema::hasColumn('items', 'mag_bonus_base')) {
            $payload['mag_bonus_base'] = $payload['mag_bonus'];
        }
        if (Schema::hasColumn('items', 'source_type')) {
            $payload['source_type'] = 'drop_weapon_evolution';
        }
        if (Schema::hasColumn('items', 'is_evolvable')) {
            $payload['is_evolvable'] = $nextExternalId !== null;
        }
        if (Schema::hasColumn('items', 'is_tradeable')) {
            $payload['is_tradeable'] = (bool) ($source->is_tradeable ?? true);
        }
        if (Schema::hasColumn('items', 'innate_killer_species_key')) {
            $payload['innate_killer_species_key'] = $source->innate_killer_species_key;
            $payload['innate_killer_damage_rate'] = (float) ($source->innate_killer_damage_rate ?? 0);
        }

        $existing = DB::table('items')->where('external_item_id', $externalId)->first();
        if ($existing) {
            DB::table('items')->where('id', $existing->id)->update($payload);
            return;
        }

        $payload['external_item_id'] = $externalId;
        $payload['created_at'] = now();
        DB::table('items')->insert($payload);
    }

    private function upsertRecipe(
        string $familyKey,
        string $familyId,
        string $familyName,
        string $templateFamilyId,
        string $path,
        string $fromRank,
        string $toRank,
        string $fromExternalId,
        string $fromName,
        string $toExternalId,
        string $toName
    ): void {
        $template = $this->templateRecipe($templateFamilyId, $path, $fromRank, $toRank);
        $recipeId = "DROP_EVO_{$familyKey}_{$fromRank}_TO_{$toRank}";
        $now = now();

        DB::table('weapon_evolution_recipes')->updateOrInsert(
            ['recipe_id' => $recipeId],
            [
                'from_weapon_id' => $fromExternalId,
                'from_weapon_name' => $fromName,
                'to_weapon_id' => $toExternalId,
                'to_weapon_name' => $toName,
                'weapon_family_id' => $familyId,
                'category_id' => $template->category_id,
                'from_rank' => $fromRank,
                'to_rank' => $toRank,
                'same_weapon_count' => 1,
                'unlock_condition' => $fromRank === 'A' || in_array($fromRank, ['S', 'SS', 'SSS'], true)
                    ? $template->unlock_condition
                    : null,
                'is_active' => true,
                'note' => "敵固有ドロップ武器の専用一本道進化。{$familyName}",
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->delete();

        $ingredients = DB::table('weapon_evolution_recipe_ingredients')
            ->where('recipe_id', $template->recipe_id)
            ->orderBy('id')
            ->get();
        if ($ingredients->isEmpty()) {
            throw new RuntimeException("テンプレート進化レシピ {$template->recipe_id} に素材がありません。");
        }

        foreach ($ingredients as $ingredient) {
            $quantity = (int) $ingredient->quantity;
            if ($fromRank === 'SS' && $toRank === 'SSS' && str_starts_with((string) $ingredient->ingredient_id, 'MAT_BR_WPN_')) {
                $quantity = 20;
            }

            DB::table('weapon_evolution_recipe_ingredients')->insert([
                'recipe_id' => $recipeId,
                'ingredient_type' => $ingredient->ingredient_type,
                'ingredient_id' => $ingredient->ingredient_id,
                'ingredient_name' => $ingredient->ingredient_name,
                'quantity' => $quantity,
                'resolve_rule' => $ingredient->resolve_rule,
                'is_consumed' => (bool) $ingredient->is_consumed,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function templateRecipe(string $familyId, string $path, string $fromRank, string $toRank): object
    {
        $query = DB::table('weapon_evolution_recipes')
            ->where('from_rank', $fromRank)
            ->where('to_rank', $toRank)
            ->where('is_active', true);

        if (in_array($fromRank, ['G', 'F', 'E', 'D', 'C', 'B'], true)) {
            $template = $query->where('weapon_family_id', $familyId)->orderBy('id')->first();
        } else {
            $pathKey = strtoupper($path);
            if ($fromRank === 'A') {
                $fromExternalId = DB::table('items')
                    ->where('weapon_family_id', $familyId)
                    ->where('weapon_rank', 'A')
                    ->value('external_item_id');
            } else {
                $fromExternalId = "WPN_BR_{$familyId}_{$pathKey}_{$fromRank}";
            }

            $toExternalId = "WPN_BR_{$familyId}_{$pathKey}_{$toRank}";
            $template = $query
                ->where('from_weapon_id', $fromExternalId)
                ->where('to_weapon_id', $toExternalId)
                ->first();
        }

        if (!$template) {
            throw new RuntimeException("{$familyId} {$path} {$fromRank}→{$toRank} のテンプレート進化レシピがありません。");
        }

        return $template;
    }

    private function standardSellPrice(string $familyId, string $rank): int
    {
        return (int) (
            DB::table('items')
                ->where('type', 'weapon')
                ->where('weapon_family_id', $familyId)
                ->where('weapon_rank', $rank)
                ->orderBy('id')
                ->value('sell_price') ?? 0
        );
    }

    private function scaledStat(int $sourceValue, float $sourceMultiplier, float $targetMultiplier, string $column): int
    {
        if ($sourceValue === 0) {
            return 0;
        }

        $unit = $column === 'hp_bonus' ? 4 : 8;
        $scaled = $sourceValue * ($targetMultiplier / $sourceMultiplier);

        return (int) (round($scaled / $unit) * $unit);
    }

    private function evolvedExternalId(string $familyKey, string $rank): string
    {
        return "DROP_EVO_{$familyKey}_{$rank}";
    }
}
