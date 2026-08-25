<?php

namespace App\Services\Nation;

final class NationHeaderBackgroundCatalog
{
    public const DEFAULT_KEY = 'nation_header_bg_001';

    public const BACKGROUND_COUNT = 20;

    /** @var array<string, array{label:string,path:string,alt:string}>|null */
    private static ?array $backgrounds = null;

    /**
     * @return array<string, array{label:string,path:string,alt:string}>
     */
    public function all(): array
    {
        if (self::$backgrounds !== null) {
            return self::$backgrounds;
        }

        $backgrounds = [];

        for ($number = 1; $number <= self::BACKGROUND_COUNT; $number++) {
            $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $backgrounds["nation_header_bg_{$suffix}"] = [
                'label' => "背景 No.{$suffix}",
                'path' => "images/nation/bg/nation-header-bg_{$suffix}.webp",
                'alt' => "国家ヘッダ背景 {$suffix}",
            ];
        }

        return self::$backgrounds = $backgrounds;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function selectableKey(?string $key): string
    {
        return $key !== null && $this->exists($key) ? $key : self::DEFAULT_KEY;
    }

    /** @return array{label:string,path:string,alt:string} */
    public function get(?string $key): array
    {
        return $this->all()[$this->selectableKey($key)];
    }
}
