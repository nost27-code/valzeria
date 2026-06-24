<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\ExplorationItemCarry;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class ExplorationItemService
{
    private const CARRY_LIMIT = 10;

    public function carriedItems(Character $character): array
    {
        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || !$state->area_id) {
            return $this->emptyItems($character);
        }

        return array_map(function (array $config) use ($character, $state) {
            $item = Item::where('type', 'consumable')->where('name', $config['name'])->first();
            if (!$item) {
                return $this->entryFromConfig($config, null, 0, 0, 0);
            }

            $ownedCount = $this->ownedCount($character, $item);
            $initialCarryCount = $this->initialCarryCount($ownedCount);
            $carry = ExplorationItemCarry::firstOrCreate(
                ['character_id' => $character->id, 'item_id' => $item->id],
                [
                    'area_id' => $state->area_id,
                    'carried_count' => $initialCarryCount,
                    'used_count' => 0,
                ]
            );

            if ((int) $carry->area_id !== (int) $state->area_id) {
                $carry->forceFill([
                    'area_id' => $state->area_id,
                    'carried_count' => $initialCarryCount,
                    'used_count' => 0,
                ])->save();
            } elseif ((int) $carry->carried_count > self::CARRY_LIMIT) {
                $carry->forceFill([
                    'carried_count' => self::CARRY_LIMIT,
                ])->save();
            }

            return $this->entryFromConfig(
                $config,
                $item,
                $ownedCount,
                (int) $carry->carried_count,
                (int) $carry->used_count
            );
        }, $this->configs());
    }

    public function use(Character $character, Item $item): array
    {
        $config = $this->configFor($item);
        if (!$config) {
            return ['success' => false, 'message' => 'このアイテムは探索中に使用できません。'];
        }

        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || !$state->area_id) {
            return ['success' => false, 'message' => '探索中のみ使用できます。'];
        }

        $this->carriedItems($character);
        $carry = ExplorationItemCarry::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->first();

        if (!$carry || ((int) $carry->carried_count - (int) $carry->used_count) <= 0) {
            return ['success' => false, 'message' => "{$item->name}の持ち込み分を使い切っています。"];
        }

        $owned = CharacterItem::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->where('is_equipped', false)
            ->oldest()
            ->first();

        if (!$owned) {
            return ['success' => false, 'message' => "{$item->name}を所持していません。"];
        }

        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $target = $config['target'];
        $max = $target === 'hp' ? (int) ($stats['max_hp'] ?? $character->hp_base) : (int) ($stats['max_mp'] ?? $character->mp_base);
        $currentColumn = $target === 'hp' ? 'current_hp' : 'current_mp';
        $current = (int) ($character->{$currentColumn} ?? 0);

        if ($max <= 0 || $current >= $max) {
            return ['success' => false, 'message' => $target === 'hp' ? 'HPはすでに全快です。' : 'SPはすでに全快です。'];
        }

        $recover = max(1, (int) ceil($max * ($config['percent'] / 100)));

        DB::transaction(function () use ($character, $currentColumn, $current, $max, $recover, $owned, $carry) {
            $character->{$currentColumn} = min($max, $current + $recover);
            $character->save();

            $owned->delete();
            $carry->increment('used_count');
        });

        $label = $target === 'hp' ? 'HP' : 'SP';
        $after = min($max, $current + $recover);

        return [
            'success' => true,
            'message' => "{$item->name}を使用し、{$label}が{$after}/{$max}まで回復しました。",
        ];
    }

    public function reset(Character $character): void
    {
        ExplorationItemCarry::where('character_id', $character->id)->delete();
    }

    private function configs(): array
    {
        return [
            ['name' => '薬草', 'target' => 'hp', 'percent' => 30],
            ['name' => '回復薬', 'target' => 'hp', 'percent' => 60],
            ['name' => '魔力水', 'target' => 'mp', 'percent' => 30],
        ];
    }

    private function configFor(Item $item): ?array
    {
        foreach ($this->configs() as $config) {
            if ($item->type === 'consumable' && $item->name === $config['name']) {
                return $config;
            }
        }

        return null;
    }

    private function emptyItems(Character $character): array
    {
        return array_map(function (array $config) use ($character) {
            $item = Item::where('type', 'consumable')->where('name', $config['name'])->first();
            return $this->entryFromConfig($config, $item, $item ? $this->ownedCount($character, $item) : 0, 0, 0);
        }, $this->configs());
    }

    private function ownedCount(Character $character, Item $item): int
    {
        return CharacterItem::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->where('is_equipped', false)
            ->count();
    }

    private function entryFromConfig(array $config, ?Item $item, int $ownedCount, int $carriedCount, int $usedCount): array
    {
        $availableCount = min($ownedCount, max(0, $carriedCount - $usedCount));

        return [
            'item_id' => $item?->id,
            'name' => $config['name'],
            'target' => $config['target'],
            'percent' => $config['percent'],
            'limit' => self::CARRY_LIMIT,
            'owned_count' => $ownedCount,
            'carried_count' => $carriedCount,
            'used_count' => $usedCount,
            'available_count' => $availableCount,
        ];
    }

    private function initialCarryCount(int $ownedCount): int
    {
        return min(self::CARRY_LIMIT, max(0, $ownedCount));
    }
}
