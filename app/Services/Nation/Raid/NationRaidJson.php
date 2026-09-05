<?php

namespace App\Services\Nation\Raid;

use RuntimeException;

/** PHP環境のserialize_precisionに左右されないレイド成果物JSON。 */
final class NationRaidJson
{
    public static function encode(mixed $value, int $flags = 0): string
    {
        $previousPrecision = ini_get('serialize_precision');
        if (! is_string($previousPrecision)) {
            throw new RuntimeException('Could not read serialize_precision for raid JSON encoding.');
        }

        $restorePrecision = $previousPrecision !== '-1';
        if ($restorePrecision && ini_set('serialize_precision', '-1') === false) {
            throw new RuntimeException('Could not set deterministic serialize_precision for raid JSON encoding.');
        }

        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        } finally {
            if ($restorePrecision && ini_set('serialize_precision', $previousPrecision) === false) {
                throw new RuntimeException('Could not restore serialize_precision after raid JSON encoding.');
            }
        }
    }
}
