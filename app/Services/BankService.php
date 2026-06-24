<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BankService
{
    public function __construct(private readonly GoldService $goldService)
    {
    }

    public function deposit(Character $character, int $amount): array
    {
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($character, $amount): array {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->money < $amount) {
                throw new RuntimeException('預けるGoldが手持ちに足りません。');
            }

            $this->goldService->spend($locked, $amount, 'bank_deposit', '銀行へGoldを預け入れ');
            $locked->bank_gold = max(0, (int) ($locked->bank_gold ?? 0)) + $amount;
            $locked->save();

            return $this->summary($locked);
        });
    }

    public function withdraw(Character $character, int $amount): array
    {
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($character, $amount): array {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();

            if ((int) ($locked->bank_gold ?? 0) < $amount) {
                throw new RuntimeException('引き出すGoldが預金に足りません。');
            }

            $locked->bank_gold = (int) $locked->bank_gold - $amount;
            $locked->save();
            $this->goldService->add($locked, $amount, 'bank_withdraw', '銀行からGoldを引き出し');

            return $this->summary($locked);
        });
    }

    public function summary(Character $character): array
    {
        return [
            'hand_gold' => max(0, (int) ($character->money ?? 0)),
            'bank_gold' => max(0, (int) ($character->bank_gold ?? 0)),
            'total_gold' => max(0, (int) ($character->money ?? 0)) + max(0, (int) ($character->bank_gold ?? 0)),
        ];
    }

    private function normalizeAmount(int $amount): int
    {
        if ($amount <= 0) {
            throw new RuntimeException('Goldの数量を1以上で指定してください。');
        }

        if ($amount > 2000000000) {
            throw new RuntimeException('1回に扱えるGoldは2,000,000,000Gまでです。');
        }

        return $amount;
    }
}
