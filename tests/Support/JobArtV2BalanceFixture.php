<?php

namespace Tests\Support;

final class JobArtV2BalanceFixture
{
    /**
     * Crown-job representatives used to detect drift in the 12pt economy.
     * A null candidate means the secondary effect is not frozen deeply enough
     * to approve a production number from raw power alone.
     *
     * @return array<string, array<string, int|string|null>>
     */
    public static function lineages(): array
    {
        return [
            'counter' => self::entry(60, 'damage', 455),
            'eclipse' => self::entry(61, 'damage', 585),
            'pierce' => self::entry(62, 'damage', 470),
            'field' => self::entry(63, 'damage', 455),
            'hunt' => self::entry(64, 'damage', 460),
            'aim' => self::entry(65, 'damage', 570),
            'guard' => self::entry(66, 'hybrid', 355),
            'transmute' => self::entry(67, 'hybrid', 355),
            'break' => self::entry(68, 'hybrid', 355),
            'command' => self::entry(69, 'damage', 455),
        ];
    }

    /** @return array<string, int|string|null> */
    private static function entry(int $jobId, string $classification, ?int $candidate): array
    {
        return [
            'job_id' => $jobId,
            'rank5' => 5,
            'rank9' => 9,
            'classification' => $classification,
            'current_rank5_power' => 285,
            'current_rank9_power' => 355,
            'candidate_rank9_power' => $candidate,
        ];
    }

    public static function successPackageRatio(float $rankFiveDamage, float $rankNineDamage): float
    {
        return $rankNineDamage / max(1.0, $rankFiveDamage * 3.0);
    }

    public static function activationAdjustedRatio(float $rankFiveDamage, float $rankNineDamage): float
    {
        return ($rankNineDamage * 0.50) / max(1.0, $rankFiveDamage * 3.0 * 0.38);
    }
}
