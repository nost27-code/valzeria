<?php

namespace App\Services;

use App\Models\Character;
use RuntimeException;

class WeaponTraitWorkshopService
{
    public function __construct(
        private readonly WeaponTraitForgeService $forgeService,
        private readonly WeaponTraitTransferService $transferService,
    ) {
    }

    /**
     * @return array{engraving: array<string, mixed>, slayer: array<string, mixed>}
     */
    public function candidates(Character $character): array
    {
        $candidates = $this->transferService->candidates($character);

        return [
            'engraving' => $candidates['engraving_transfer'],
            'slayer' => $candidates['slayer_transfer'],
        ];
    }

    /**
     * @return array{message: string, base_character_item_id: int, gold_cost: int}
     */
    public function process(
        Character $character,
        string $traitKind,
        string $action,
        int $baseCharacterItemId,
        int $materialCharacterItemId,
        bool $useBank = false,
    ): array {
        if (!in_array($traitKind, ['engraving', 'slayer'], true)) {
            throw new RuntimeException('鍛える特性が不正です。');
        }

        if ($action === 'dual') {
            return $useBank
                ? $this->forgeService->forge($character, 'dual_forge', $baseCharacterItemId, $materialCharacterItemId, true)
                : $this->forgeService->forge($character, 'dual_forge', $baseCharacterItemId, $materialCharacterItemId);
        }

        if ($action === 'forge') {
            $operation = $traitKind === 'engraving' ? 'engraving_forge' : 'slayer_forge';

            return $useBank
                ? $this->forgeService->forge($character, $operation, $baseCharacterItemId, $materialCharacterItemId, true)
                : $this->forgeService->forge($character, $operation, $baseCharacterItemId, $materialCharacterItemId);
        }

        if ($action === 'transfer') {
            $operation = $traitKind === 'engraving' ? 'engraving_transfer' : 'slayer_transfer';

            return $useBank
                ? $this->transferService->transfer($character, $operation, $baseCharacterItemId, $materialCharacterItemId, true)
                : $this->transferService->transfer($character, $operation, $baseCharacterItemId, $materialCharacterItemId);
        }

        throw new RuntimeException('加工方法が不正です。');
    }
}
