<?php

namespace App\Services;

use App\Models\Character;
use App\Models\NamelessEquipmentDiscovery;
use App\Models\PlayerNamelessEquipment;
use RuntimeException;

class NamelessEquipmentCollectionService
{
    public function types(): array
    {
        $types = [];
        foreach (NamelessEquipmentService::KINDS as $kind) {
            foreach (NamelessEquipmentService::statOptionsFor($kind) as $type => $stat) {
                $types[$type] = ['kind' => $kind, 'label' => '名もなき'.$type, 'stat' => $stat['label']];
            }
        }
        return $types;
    }

    public function summary(Character $character): array
    {
        $found = NamelessEquipmentDiscovery::query()->where('character_id', $character->id)->pluck('equipment_type')->all();
        $types = $this->types();
        $ownedTypes = PlayerNamelessEquipment::query()->where('character_id', $character->id)->distinct()->pluck('equipment_type')->all();
        // 入手一覧は初期配布も含む所持種類を表示する。遺跡の未発見優先抽選には従来のfoundを使う。
        $acquired = array_values(array_intersect(array_keys($types), array_unique(array_merge($found, $ownedTypes))));
        return ['found' => $found, 'count' => count($found), 'acquired' => $acquired, 'acquired_count' => count($acquired), 'total' => count($types), 'types' => $types];
    }

    public function freeSlots(Character $character): int
    {
        return app(StorageCapacityService::class)->summary($character)['equipment_free'];
    }

    public function dropChanceBpsAtDepth(int $depth): int
    {
        $base = (int) config('nameless_relics.equipment_drop_chance_bps');
        $multiplier = (float) config('nameless_relics.equipment_drop_depth_multiplier_at_max', 1);
        if ($base < 0 || $base > 10000 || ! is_finite($multiplier) || $multiplier < 1) {
            throw new RuntimeException('武具の抽選設定が不正です。');
        }
        $maxDepth = max(1, (int) config('nameless_relics.max_depth'));
        $progress = (max(1, min($depth, $maxDepth)) - 1) / max(1, $maxDepth - 1);

        // 0%/100%の隔離検証設定も維持する。1枚の抽選につき武具は最大1個。
        return (int) min(10000, round($base * (1 + ($multiplier - 1) * $progress)));
    }

    public function dropsForTicket(int $ticket, int $depth = 1): bool
    {
        if ($ticket < 1 || $ticket > 10000) {
            throw new RuntimeException('武具の抽選設定が不正です。');
        }

        return $ticket <= $this->dropChanceBpsAtDepth($depth);
    }

    /** 遺跡勝利時のみ呼ぶ。呼出元の操作transactionでキャラクター行をロック済み。 */
    public function awardLocked(Character $character): array
    {
        app(NamelessWorkshopService::class)->assertAvailable();
        if ($this->freeSlots($character) === 0) {
            throw new RuntimeException('名もなき武具の所持枠がいっぱいです。');
        }
        $collection = $this->summary($character);
        $unfound = array_diff(array_keys($collection['types']), $collection['found']);
        $candidates = array_values($unfound ?: array_keys($collection['types']));
        $type = $candidates[random_int(0, count($candidates) - 1)];
        $kind = $collection['types'][$type]['kind'];
        $body = PlayerNamelessEquipment::query()->create([
            'character_id' => $character->id, 'kind' => $kind, 'equipment_type' => $type,
            'acquisition_source' => 'ruin', 'forge_level' => 0, 'base_power' => 5,
            'power_per_level' => 5, 'is_equipped' => false,
        ]);
        $record = NamelessEquipmentDiscovery::query()->firstOrCreate([
            'character_id' => $character->id, 'equipment_type' => $type,
        ], ['kind' => $kind]);
        return ['id' => $body->id, 'name' => $body->displayName(), 'kind' => $kind,
            'equipment_type' => $type, 'new_discovery' => $record->wasRecentlyCreated, 'forge_level' => 0];
    }
}
