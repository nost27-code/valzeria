<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class ExplorationMapSeedService
{
    public function createRootSeed(string $mapUuid, string $dropEventUuid, int $characterId, int $areaId, int $monsterId, int $generationVersion = 1): string
    {
        $material = implode('|', ['exploration-map', "generation-version:{$generationVersion}", "map:{$mapUuid}", "drop:{$dropEventUuid}", "owner:{$characterId}", "area:{$areaId}", "monster:{$monsterId}"]);

        return hash_hmac('sha256', $material, (string) config('exploration_maps.seed_secret'));
    }

    public function encrypt(string $rootSeed): string { return Crypt::encryptString($rootSeed); }
    public function decrypt(string $encrypted): string { return Crypt::decryptString($encrypted); }
    public function hash(string $rootSeed): string { return hash('sha256', $rootSeed); }

    public function int(string $rootSeed, string $context, int $min, int $max): int
    {
        if ($min > $max) throw new \InvalidArgumentException('地図seedの抽選範囲が不正です。');
        $bytes = hash_hmac('sha256', $context, hex2bin($rootSeed), true);
        $value = unpack('Nvalue', substr($bytes, 0, 4))['value'];

        return $min + ($value % ($max - $min + 1));
    }

    public function weightedPick(string $rootSeed, string $context, array $candidates): array
    {
        $total = array_sum(array_map(fn (array $candidate) => max(0, (int) ($candidate['weight'] ?? 0)), $candidates));
        if ($total <= 0) throw new \RuntimeException('地図生成候補がありません。');
        $roll = $this->int($rootSeed, $context, 1, $total);
        foreach ($candidates as $candidate) {
            $roll -= max(0, (int) ($candidate['weight'] ?? 0));
            if ($roll <= 0) return $candidate;
        }
        return end($candidates);
    }

    public function explorationSeed(string $rootSeed, string $type, int $index, int $characterId): string
    {
        $context = $type === 'encounter'
            ? "encounter:v1:{$index}"
            : "reward:v1|{$index}|{$characterId}";
        return hash_hmac('sha256', $context, hex2bin($rootSeed));
    }
}
