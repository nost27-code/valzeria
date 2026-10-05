<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\NamelessRuinProgress;
use RuntimeException;
use Illuminate\Support\Facades\DB;

class NamelessTownService
{
    public const NAME = '無もなき工房街';

    public const MARKER = 'nameless_relic_local';

    public const IMAGE = 'images/cities/nameless-workshop.webp';

    public const ICON = 'images/facilities/nameless-workshop.webp';

    public function isTown(?City $city): bool
    {
        return $city?->unlock_condition_type === self::MARKER;
    }

    public function availableTown(): ?City
    {
        return app(NamelessWorkshopService::class)->ready()
            ? City::query()->where('unlock_condition_type', self::MARKER)->first()
            : null;
    }

    public function installLocalTown(): City
    {
        return $this->installTown();
    }

    public function installTown(bool $prepareOff = false): City
    {
        $workshop = app(NamelessWorkshopService::class);
        if ($prepareOff) {
            if ($workshop->enabled() || ! app(NamelessPreparationService::class)->status()['ready']) {
                throw new RuntimeException('OFF状態かつDB移行確認済みの場合だけ街を事前登録できます。');
            }
        } else {
            $workshop->assertAvailable();
        }
        $connection = DB::connection();
        $maria = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        // citiesにはmarkerのuniqueがないので、同時実行による重複登録を防ぐ。
        $lock = 'valzeria.nameless.install-town';
        if ($maria && (int) $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired !== 1) {
            throw new RuntimeException('工房街登録が実行中です。完了後に再実行してください。');
        }
        try {
            return $connection->transaction(function (): City {
                $existing = City::query()->where('unlock_condition_type', self::MARKER)->get();
                if ($existing->count() > 1) {
                    throw new RuntimeException('工房街が重複しています。既存データを確認してください。');
                }
                $town = $existing->first() ?? City::query()->create([
                    'unlock_condition_type' => self::MARKER,
                    'name' => self::NAME,
                    'description' => '古い遺跡のふもとに築かれた、探索者と職人の小さな街。持ち帰った遺物を武具に宿し、次の深みへ備える。',
                    'sort_order' => 0, 'is_initial' => false,
                    'recommended_level_min' => 1, 'recommended_level_max' => 255,
                ]);
                if ($town->name !== self::NAME) {
                    $town->update(['name' => self::NAME]);
                }
                return $town;
            }, 3);
        } finally {
            if ($maria) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    public function assertVisiting(Character $character): void
    {
        $town = $this->availableTown();
        if (! $town || (int) $character->current_city_id !== (int) $town->id) {
            throw new RuntimeException('MAPから無もなき工房街へ移動してから利用してください。');
        }
    }

    public function normalProgressCityId(Character $character, int $fallback = 1): int
    {
        // 旧データの最高到達街が未設定でも、工房のIDで通常進行条件を満たさせない。
        if (! $character->highest_city_id && $this->isTown($character->currentCity)) {
            return (int) (City::query()->where('is_initial', true)->value('id') ?? $fallback);
        }

        return (int) ($character->highest_city_id ?: $character->current_city_id ?: $fallback);
    }

    public function townFacilities(array $facilities): array
    {
        $workshop = [
            'category' => '鍛冶屋', 'name' => '名もなき鍛冶屋',
            'symbol_image' => 'facilities/nameless-workshop.webp',
            'desc' => '遺物を宿し、余った素材や遺物を注いで武具を育てる',
            'details' => ['武器・防具・装飾品', '遺物の付け替え・吸収'],
            'bg_image' => 'cities/nameless-workshop.webp', 'status' => 'active',
            'action' => '工房に入る', 'route' => 'nameless-workshop.index', 'is_post' => false,
        ];

        return [$workshop, ...array_values(array_filter($facilities,
            fn (array $facility) => in_array($facility['route'] ?? '', ['inn.rest', 'shop.items', 'bank.index'], true)))];
    }

    public function ruinFacilities(Character $character): array
    {
        $progress = NamelessRuinProgress::query()->where('character_id', $character->id)->pluck('unlocked_depth', 'zone_key');
        $effects = app(NamelessRelicCatalog::class)->all();
        $inventoryBlockReason = app(NamelessWorkshopService::class)->inventoryBlockReason($character);
        $facilities = [];
        foreach (app(NamelessRuinService::class)->availableZones($character) as $key => $zone) {
            $facilities[] = [
                'nameless_ruin' => true, 'zone_key' => $key, 'name' => $zone['name'], 'desc' => $zone['description'],
                'inventory_block_reason' => $inventoryBlockReason,
                'image' => self::ruinImage($key), 'symbol_image' => self::ruinSymbol($key),
                'depth' => (int) $progress->get($key, 1),
                'effects' => array_map(fn ($effect) => $effects[$effect]['name'], $zone['effects']),
                'enemies' => $zone['enemies'],
                'next_zone_name' => (int) $progress->get($key, 1) <= (int) config('nameless_relics.next_zone_unlock_depth') ? $zone['next_zone_name'] : null,
            ];
        }

        return $facilities;
    }

    public static function ruinImage(string $key): string
    {
        return 'images/nameless-ruins/'.$key.'.webp';
    }

    public static function ruinSymbol(string $key): string
    {
        return 'images/nameless-ruins/'.$key.'-symbol.webp';
    }
}
