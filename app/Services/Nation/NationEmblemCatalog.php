<?php

namespace App\Services\Nation;

final class NationEmblemCatalog
{
    public const DEFAULT_KEY = 'nation_crest_001';

    public const NUMBERED_EMBLEM_COUNT = 80;

    /** @var array<string, string> */
    private const LEGACY_KEY_MAP = [
        'green_castle' => 'nation_crest_001',
        'blue_shield' => 'nation_crest_002',
    ];

    /** @var array<string, array{label:string,path:string,alt:string}>|null */
    private static ?array $emblems = null;

    /**
     * @return array<string, array{label:string,path:string,alt:string}>
     */
    public function all(): array
    {
        if (self::$emblems !== null) {
            return self::$emblems;
        }

        $emblems = [];

        for ($number = 1; $number <= self::NUMBERED_EMBLEM_COUNT; $number++) {
            $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $emblems["nation_crest_{$suffix}"] = [
                'label' => "紋章 {$suffix}",
                'path' => "images/nation/nation-crest_{$suffix}.webp",
                'alt' => "国家紋章 {$suffix}",
            ];
        }

        return self::$emblems = $emblems;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function selectableKey(?string $key): string
    {
        if ($key !== null && $this->exists($key)) {
            return $key;
        }

        return self::LEGACY_KEY_MAP[$key ?? ''] ?? self::DEFAULT_KEY;
    }

    /** @return array{label:string,path:string,alt:string} */
    public function get(?string $key): array
    {
        return $this->all()[$this->selectableKey($key)];
    }
}
