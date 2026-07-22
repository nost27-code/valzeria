<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\MapExplorationItemCarry;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class MapExplorationItemService
{
    private const CARRY_LIMIT = 10;

    /**
     * 地図への入場時点の所持数から、地図内で使える回復アイテムを確定する。
     */
    public function begin(Character $character, TownMapRegistration $registration): void
    {
        DB::transaction(function () use ($character, $registration) {
            MapExplorationItemCarry::where('character_id', $character->id)->delete();

            foreach ($this->configs() as $config) {
                $item = $this->itemForConfig($config);
                if (!$item) {
                    continue;
                }

                MapExplorationItemCarry::create([
                    'character_id' => $character->id,
                    'registration_id' => $registration->id,
                    'item_id' => $item->id,
                    'carried_count' => min(self::CARRY_LIMIT, $this->ownedCount($character, $item)),
                    'used_count' => 0,
                ]);
            }
        });
    }

    public function carriedItems(Character $character, int $registrationId): array
    {
        return array_map(function (array $config) use ($character, $registrationId) {
            $item = $this->itemForConfig($config);
            if (!$item) {
                return $this->entryFromConfig($config, null, 0, 0, 0);
            }

            $carry = MapExplorationItemCarry::query()
                ->where('character_id', $character->id)
                ->where('registration_id', $registrationId)
                ->where('item_id', $item->id)
                ->first();

            return $this->entryFromConfig(
                $config,
                $item,
                $this->ownedCount($character, $item),
                (int) ($carry?->carried_count ?? 0),
                (int) ($carry?->used_count ?? 0),
            );
        }, $this->configs());
    }

    public function hasEntry(Character $character, int $registrationId): bool
    {
        return MapExplorationItemCarry::query()
            ->where('character_id', $character->id)
            ->where('registration_id', $registrationId)
            ->exists();
    }

    public function use(Character $character, Item $item, int $registrationId): array
    {
        $config = $this->configFor($item);
        if (!$config) {
            return ['success' => false, 'message' => 'このアイテムは探索中に使用できません。'];
        }

        return DB::transaction(function () use ($character, $item, $registrationId, $config) {
            $carry = MapExplorationItemCarry::query()
                ->where('character_id', $character->id)
                ->where('registration_id', $registrationId)
                ->where('item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if (!$carry) {
                return ['success' => false, 'message' => 'この地図には回復アイテムを持ち込んでいません。'];
            }

            if ((int) $carry->carried_count <= (int) $carry->used_count) {
                return ['success' => false, 'message' => "{$item->name}の持ち込み分を使い切っています。"];
            }

            $owned = CharacterItem::query()
                ->where('character_id', $character->id)
                ->where('item_id', $item->id)
                ->where('is_equipped', false)
                ->oldest()
                ->lockForUpdate()
                ->first();

            if (!$owned) {
                return ['success' => false, 'message' => "{$item->name}を所持していません。"];
            }

            $character = Character::query()->lockForUpdate()->findOrFail($character->id);
            $stats = app(CharacterStatusService::class)->getFinalStats($character);
            $target = $config['target'];
            $max = $target === 'hp' ? (int) ($stats['max_hp'] ?? $character->hp_base) : (int) ($stats['max_mp'] ?? $character->mp_base);
            $currentColumn = $target === 'hp' ? 'current_hp' : 'current_mp';
            $current = (int) ($character->{$currentColumn} ?? 0);

            if ($max <= 0 || $current >= $max) {
                return ['success' => false, 'message' => $target === 'hp' ? 'HPはすでに全快です。' : 'SPはすでに全快です。'];
            }

            $recover = max(1, (int) ceil($max * ($config['percent'] / 100)));
            $after = min($max, $current + $recover);

            $character->{$currentColumn} = $after;
            $character->save();
            $owned->delete();
            $carry->increment('used_count');

            $label = $target === 'hp' ? 'HP' : 'SP';

            return [
                'success' => true,
                'message' => "{$item->name}を使用し、{$label}が{$after}/{$max}まで回復しました。",
            ];
        });
    }

    public function end(Character $character): void
    {
        MapExplorationItemCarry::where('character_id', $character->id)->delete();
    }

    private function configs(): array
    {
        return [
            ['name' => '薬草', 'target' => 'hp', 'percent' => 30],
            ['name' => '回復薬', 'target' => 'hp', 'percent' => 60],
            ['name' => '魔力水', 'target' => 'mp', 'percent' => 30],
        ];
    }

    private function itemForConfig(array $config): ?Item
    {
        return Item::where('type', 'consumable')->where('name', $config['name'])->first();
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

    private function ownedCount(Character $character, Item $item): int
    {
        return CharacterItem::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->where('is_equipped', false)
            ->count();
    }

    private function entryFromConfig(array $config, ?Item $item, int $ownedCount, int $carriedCount, int $usedCount): array
    {
        return [
            'item_id' => $item?->id,
            'name' => $config['name'],
            'target' => $config['target'],
            'percent' => $config['percent'],
            'limit' => self::CARRY_LIMIT,
            'owned_count' => $ownedCount,
            'carried_count' => $carriedCount,
            'used_count' => $usedCount,
            'available_count' => min($ownedCount, max(0, $carriedCount - $usedCount)),
        ];
    }
}
