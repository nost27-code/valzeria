<?php

namespace App\Services;

use App\Models\Character;

class KisekiBalanceService
{
    /**
     * 行ロック済みのキャラクターから、無償輝石を優先して消費する。
     *
     * @return array{free_spent: int, paid_spent: int}
     */
    public function spendLocked(Character $character, int $amount): array
    {
        $amount = max(0, $amount);
        $free = (int) ($character->free_kiseki ?? 0);
        $paid = (int) ($character->paid_kiseki ?? 0);

        if (($free + $paid) < $amount) {
            throw new \RuntimeException('輝石が不足しています。');
        }

        $freeSpent = min($free, $amount);
        $paidSpent = $amount - $freeSpent;

        $character->free_kiseki = $free - $freeSpent;
        $character->paid_kiseki = $paid - $paidSpent;
        $character->kiseki = (int) $character->free_kiseki + (int) $character->paid_kiseki;
        $character->save();

        return [
            'free_spent' => $freeSpent,
            'paid_spent' => $paidSpent,
        ];
    }
}
