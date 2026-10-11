<?php

namespace App\Services\Battle;

/** 表示専用。遺物の軽減だけを除いた同一ヒットの整数値を運び、戦闘へ適用しない。 */
final class RelicDamageTrace
{
    public int $mitigatedDamage = 0;

    public function __construct(public int $damageWithoutMitigation) {}
}
