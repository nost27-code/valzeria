<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Nation;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationResourceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 国家資材を変更するtransactionでは、次の順でrow lockを取得する。
 *
 * 1. nations (exclusive)
 * 2. nation_memberships (exclusive, 納品時のみ)
 * 3. nation_material_conversion_rates (shared, 納品時のみ)
 * 4. character_materials (exclusive, 納品時のみ)
 *
 * 所属確認用の最初のqueryはnation_idを解決する非lock snapshotであり、
 * 国家row取得後にmembershipをlockして所属が変わっていないことを再検証する。
 */
final class NationResourceService
{
    public const DONATION_LOCK_ORDER = [
        'nations',
        'nation_memberships',
        'nation_material_conversion_rates',
        'character_materials',
    ];

    public function donate(Character $character, int $materialId, int $quantity, ?string $idempotencyKey = null): NationResourceTransaction
    {
        throw_if($quantity < 1, \DomainException::class, '納品数は1以上で指定してください。');

        if ($idempotencyKey) {
            $existing = NationResourceTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->matchingDonation($existing, $character, $materialId, $quantity);
            }
        }

        try {
            return DB::transaction(function () use ($character, $materialId, $quantity, $idempotencyKey): NationResourceTransaction {
                $membershipSnapshot = NationMembership::where('character_id', $character->id)->first();
                throw_unless($membershipSnapshot, \DomainException::class, '国家へ所属していません。');

                $nation = Nation::whereKey($membershipSnapshot->nation_id)->lockForUpdate()->firstOrFail();
                $membership = NationMembership::whereKey($membershipSnapshot->id)
                    ->where('nation_id', $nation->id)
                    ->where('character_id', $character->id)
                    ->lockForUpdate()
                    ->first();
                throw_unless($membership, \DomainException::class, '所属国家が変更されました。画面を更新して、もう一度お試しください。');

                $rate = NationMaterialConversionRate::where('material_id', $materialId)
                    ->where('is_active', true)
                    ->sharedLock()
                    ->first();
                throw_unless($rate, \DomainException::class, 'この素材は国家資材へ換算できません。');

                $stock = CharacterMaterial::where('character_id', $character->id)
                    ->where('material_id', $materialId)
                    ->lockForUpdate()
                    ->first();
                throw_if(! $stock || $stock->quantity < $quantity, \DomainException::class, '素材の所持数が足りません。');

                $points = $quantity * (int) $rate->points_per_unit;
                $stock->quantity -= $quantity;
                $stock->save();
                $nation->treasury_points += $points;
                $nation->save();

                return NationResourceTransaction::create([
                    'nation_id' => $nation->id, 'character_id' => $character->id, 'material_id' => $materialId,
                    'transaction_type' => 'donation', 'quantity' => $quantity, 'points_delta' => $points,
                    'balance_after' => $nation->treasury_points, 'idempotency_key' => $idempotencyKey,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if ($idempotencyKey) {
                $existing = NationResourceTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $this->matchingDonation($existing, $character, $materialId, $quantity);
                }
            }

            throw $exception;
        }
    }

    public function spend(Nation $nation, int $points, string $type, array $metadata = [], ?int $warId = null): NationResourceTransaction
    {
        throw_if($points < 1, \DomainException::class, '消費ポイントが不正です。');

        return DB::transaction(function () use ($nation, $points, $type, $metadata, $warId): NationResourceTransaction {
            $locked = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
            throw_if($locked->treasury_points < $points, \DomainException::class, '国家資材が足りません。');
            $locked->treasury_points -= $points;
            $locked->save();

            return NationResourceTransaction::create([
                'nation_id' => $locked->id, 'nation_war_id' => $warId, 'transaction_type' => $type,
                'points_delta' => -$points, 'balance_after' => $locked->treasury_points, 'metadata' => $metadata,
            ]);
        }, 3);
    }

    public function credit(Nation $nation, int $points, string $type, array $metadata = [], ?int $warId = null): NationResourceTransaction
    {
        return DB::transaction(function () use ($nation, $points, $type, $metadata, $warId): NationResourceTransaction {
            $locked = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $credited = max(0, $points);
            $locked->treasury_points += $credited;
            $locked->save();

            return NationResourceTransaction::create([
                'nation_id' => $locked->id,
                'nation_war_id' => $warId,
                'transaction_type' => $type,
                'points_delta' => $credited,
                'balance_after' => $locked->treasury_points,
                'metadata' => $metadata,
            ]);
        }, 3);
    }

    private function matchingDonation(
        NationResourceTransaction $transaction,
        Character $character,
        int $materialId,
        int $quantity,
    ): NationResourceTransaction {
        $matches = $transaction->transaction_type === 'donation'
            && (int) $transaction->character_id === (int) $character->id
            && (int) $transaction->material_id === $materialId
            && (int) $transaction->quantity === $quantity;

        throw_unless($matches, \DomainException::class, '同じ送信キーが異なる納品内容で再利用されました。画面を更新して、もう一度お試しください。');

        return $transaction;
    }
}
