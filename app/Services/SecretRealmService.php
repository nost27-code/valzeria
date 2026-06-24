<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\Material;

class SecretRealmService
{
    private const REALMS = [
        'dragon_sanctuary' => [
            'name' => '竜王の聖域',
            'codes' => ['MAT_BR_WPN_HOLY_SECRET'],
            'keywords' => ['竜', '天空', '雷鳴', '浮遊', '王都', '墓地', '妖精'],
        ],
        'abyss_rift' => [
            'name' => '深淵の裂け目',
            'codes' => ['MAT_BR_WPN_DARK_SECRET', 'MAT_BR_ARM_ARCANE_SECRET'],
            'keywords' => ['深淵', '魔王', '瘴気', '奈落', '闇'],
        ],
        'ancient_furnace' => [
            'name' => '古代錬成炉',
            'codes' => ['MAT_BR_ARM_HEAVY_SECRET'],
            'keywords' => ['錬成', '機械', '地底', '兵器', '工場'],
        ],
        'dimensional_corridor' => [
            'name' => '次元回廊',
            'codes' => ['MAT_BR_WPN_DARK_SECRET', 'MAT_BR_ARM_ARCANE_SECRET'],
            'keywords' => ['次元', '星見', '異界', '観測'],
        ],
        'wind_spirit_valley' => [
            'name' => '風精の隠れ谷',
            'codes' => ['MAT_BR_WPN_GALE_SECRET', 'MAT_BR_ARM_LIGHT_SECRET'],
            'keywords' => ['風', '世界樹', '精霊', '月光'],
        ],
        'star_sand_pilgrimage' => [
            'name' => '星砂の巡礼路',
            'codes' => ['MAT_BR_ARM_TRAVELER_SECRET'],
            'keywords' => ['星砂', '砂', '巡礼', '海賊', '珊瑚', '深海', '流砂'],
        ],
    ];

    public function gateRate(int $explorationPoint): float
    {
        return match (true) {
            $explorationPoint >= 1000 => 8.0,
            $explorationPoint >= 700 => 3.0,
            $explorationPoint >= 500 => 1.0,
            default => 0.0,
        };
    }

    public function realmForArea(Area $area): array
    {
        $text = (string) $area->name . ' ' . (string) ($area->city?->name ?? '');

        foreach (self::REALMS as $key => $realm) {
            foreach ($realm['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return ['key' => $key] + $realm;
                }
            }
        }

        $cityId = (int) ($area->city_id ?? 0);
        $fallbackKey = match (true) {
            in_array($cityId, [1, 9], true) => 'dragon_sanctuary',
            in_array($cityId, [10], true) => 'abyss_rift',
            in_array($cityId, [5], true) => 'ancient_furnace',
            in_array($cityId, [8], true) => 'dimensional_corridor',
            in_array($cityId, [7], true) => 'wind_spirit_valley',
            in_array($cityId, [3, 4], true) => 'star_sand_pilgrimage',
            default => array_rand(self::REALMS),
        };

        return ['key' => $fallbackKey] + self::REALMS[$fallbackKey];
    }

    public function makeSecretRealmLord(Area $area, Enemy $baseEnemy, array $realm): Enemy
    {
        $boss = Enemy::where('area_id', $area->id)->where('is_boss', true)->first() ?: $baseEnemy;
        $enemy = $boss->replicate();
        $enemy->id = $boss->id;
        $enemy->exists = true;
        $enemy->setRelation('area', $area);
        $enemy->name = $realm['name'] . 'の秘境主';
        $enemy->role = '秘境主';
        $enemy->type_name = $boss->type_name ?? '高難度';
        $enemy->max_hp = max(1, (int) floor((int) $boss->max_hp * 1.15));
        $enemy->str = max(1, (int) floor((int) $boss->str * 1.12));
        $enemy->def = max(1, (int) floor((int) $boss->def * 1.12));
        $enemy->mag = max(1, (int) floor((int) ($boss->mag ?? $boss->str) * 1.12));
        $enemy->spr = max(1, (int) floor((int) ($boss->spr ?? $boss->def) * 1.12));
        $enemy->agi = max(1, (int) floor((int) $boss->agi * 1.08));
        $enemy->exp_reward = max((int) $baseEnemy->exp_reward * 4, (int) floor((int) $boss->exp_reward * 0.6));
        $enemy->gold_reward = 0;
        $enemy->job_exp_reward = max(5, (int) ($boss->job_exp_reward ?? 0));
        $enemy->is_boss = false;

        return $enemy;
    }

    public function gather(Character $character, Area $area, Enemy $baseEnemy, DropService $dropService): array
    {
        $realm = $this->realmForArea($area);
        $logs = ["【秘境】{$realm['name']}へ足を踏み入れた。"];
        $drops = [];
        $attempts = rand(2, 3);

        for ($i = 1; $i <= $attempts; $i++) {
            $roll = rand(1, 100);
            if ($roll <= 30) {
                $material = $this->randomSecretCrystal($realm);
                $label = '秘境晶';
            } elseif ($roll <= 70) {
                $material = $this->randomSecretShard($realm);
                $label = '秘境晶片';
            } elseif ($roll <= 90) {
                $material = $this->randomSecretShard($realm);
                $label = '秘境晶片';
            } else {
                $logs[] = "採取ポイント{$i}: 使える素材は見つからなかった。";
                continue;
            }

            if (!$material) {
                $logs[] = "採取ポイント{$i}: 気配はあったが、素材を取り出せなかった。";
                continue;
            }

            $drop = $dropService->grantMaterialReward($character, $material, 'secret_realm_gather', $baseEnemy);
            $drops[] = $drop;
            $logs[] = "採取ポイント{$i}: {$label}「{$drop['name']}」を手に入れた。";
        }

        return [
            'realm' => $realm,
            'drops' => $drops,
            'logs' => $logs,
        ];
    }

    public function grantLordRewards(Character $character, Area $area, Enemy $sourceEnemy, array $realm, DropService $dropService): array
    {
        $drops = [];
        $logs = [];

        $crystal = $this->randomSecretCrystal($realm);
        if ($crystal) {
            $drop = $dropService->grantMaterialReward($character, $crystal, 'secret_realm_lord', $sourceEnemy);
            $drops[] = $drop;
            $logs[] = "【秘境主報酬】{$drop['name']} を手に入れた！";
        }

        $shardCount = rand(1, 3);
        for ($i = 0; $i < $shardCount; $i++) {
            $shard = $this->randomSecretShard($realm);
            if (!$shard) {
                continue;
            }

            $drop = $dropService->grantMaterialReward($character, $shard, 'secret_realm_lord', $sourceEnemy);
            $drops[] = $drop;
        }

        if ($shardCount > 0) {
            $logs[] = "【秘境主報酬】秘境晶片を追加で {$shardCount} 個手に入れた。";
        }

        return [
            'drops' => $drops,
            'logs' => $logs,
        ];
    }

    private function randomSecretCrystal(array $realm): ?Material
    {
        $codes = $realm['codes'] ?? [];
        if (empty($codes)) {
            return null;
        }

        return Material::whereIn('material_code', $codes)->inRandomOrder()->first();
    }

    private function randomSecretShard(array $realm): ?Material
    {
        $codes = array_map(fn (string $code): string => $this->shardCode($code), $realm['codes'] ?? []);
        if (empty($codes)) {
            return null;
        }

        return Material::whereIn('material_code', $codes)->inRandomOrder()->first();
    }

    public function shardCode(string $secretCode): string
    {
        return str_replace('_SECRET', '_SECRET_SHARD', $secretCode);
    }
}
