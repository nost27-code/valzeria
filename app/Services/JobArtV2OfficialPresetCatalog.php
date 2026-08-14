<?php

namespace App\Services;

use App\Models\Skill;

final class JobArtV2OfficialPresetCatalog
{
    public const ADVANCED = 'advanced';
    public const SUPER = 'super';
    public const CROWN = 'crown';

    /** @return array<int, string> */
    public function lineages(): array
    {
        return [
            'counter',
            'eclipse',
            'pierce',
            'field',
            'hunt',
            'aim',
            'guard',
            'transmute',
            'break',
            'command',
        ];
    }

    /** @return array<int, string> */
    public function styles(): array
    {
        return [
            JobArtV2StarterPresetService::FINISHER,
            JobArtV2StarterPresetService::CYCLE,
            JobArtV2StarterPresetService::TACTICAL,
        ];
    }

    /** @return array<int, string> */
    public function variants(): array
    {
        return [self::ADVANCED, self::SUPER, self::CROWN];
    }

    /** @return array<string, mixed>|null */
    public function preset(?string $lineage, string $style): ?array
    {
        if (! is_string($lineage)
            || ! in_array($lineage, $this->lineages(), true)
            || ! in_array($style, $this->styles(), true)
        ) {
            return null;
        }

        $preset = config("job_art_official_presets.{$lineage}.{$style}");

        return is_array($preset) ? $preset : null;
    }

    /** @return array<string, mixed>|null */
    public function variant(?string $lineage, string $style, string $variant): ?array
    {
        if (! in_array($variant, $this->variants(), true)) {
            return null;
        }

        $preset = $this->preset($lineage, $style);
        $definition = $preset['variants'][$variant] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function variantLabel(string $variant): string
    {
        return match ($variant) {
            self::ADVANCED => '上級版',
            self::SUPER => '超級版',
            self::CROWN => '冠位版',
            default => '構成版',
        };
    }

    public function skillKey(Skill $skill): string
    {
        return $this->naturalKey((int) $skill->job_id, (int) $skill->learn_rank);
    }

    public function naturalKey(int $jobId, int $rank): string
    {
        return "{$jobId}:{$rank}";
    }
}
