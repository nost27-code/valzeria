<?php

namespace App\Support;

/** Converts the job-art master power hint into the persisted numeric power. */
final class JobArtMasterPowerParser
{
    public static function parse(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (preg_match('/\d+/', (string) $value, $matches)) {
            return max(0, (int) $matches[0]);
        }

        return 100;
    }
}
