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

    public function paymentSummary(Character $character, int $amount): array
    {
        $amount = max(0, $amount);
        $balances = $this->summary($character);
        $handGoldUsed = min($balances['hand_gold'], $amount);
        $bankGoldUsed = max(0, $amount - $handGoldUsed);

        return [
            ...$balances,
            'amount' => $amount,
            'hand_gold_used' => $handGoldUsed,
            'bank_gold_used' => $bankGoldUsed,
            'requires_bank' => $bankGoldUsed > 0,
            'can_pay' => $balances['total_gold'] >= $amount,
        ];
    }

    /**
     * The caller must hold a row lock for the supplied character.
     */
    public function spendForPayment(
        Character $character,
        int $amount,
        bool $bankConfirmed,
        string $type,
        ?string $note = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = []
    ): array {
        $amount = $this->normalizeAmount($amount);
        $payment = $this->paymentSummary($character, $amount);

        if (!$payment['can_pay']) {
            throw new RuntimeException('Goldが不足しています。');
        }

        if ($payment['requires_bank'] && !$bankConfirmed) {
            throw new RuntimeException('銀行預金を使う確認が必要です。');
        }

        $character->money = $payment['hand_gold'] - $payment['hand_gold_used'];
        $character->bank_gold = $payment['bank_gold'] - $payment['bank_gold_used'];
        $character->save();

        $this->goldService->record(
            $character,
            $type,
            -$amount,
            $note,
            $sourceType,
            $sourceId,
            [
                ...$metadata,
                'payment_hand_gold' => $payment['hand_gold_used'],
                'payment_bank_gold' => $payment['bank_gold_used'],
                'bank_balance_after' => (int) $character->bank_gold,
            ]
        );

        return [
            ...$payment,
            'hand_gold_after' => (int) $character->money,
            'bank_gold_after' => (int) $character->bank_gold,
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
