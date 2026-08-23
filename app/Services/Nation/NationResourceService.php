<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Nation;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationResourceTransaction;
use Illuminate\Support\Facades\DB;

final class NationResourceService
{
    public function donate(Character $character, int $materialId, int $quantity, ?string $idempotencyKey = null): NationResourceTransaction
    {
        throw_if($quantity < 1, \DomainException::class, '納品数は1以上で指定してください。');

        return DB::transaction(function () use ($character, $materialId, $quantity, $idempotencyKey): NationResourceTransaction {
            if ($idempotencyKey) {
                $existing = NationResourceTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) return $existing;
            }
            $membership = NationMembership::where('character_id', $character->id)->first();
            throw_unless($membership, \DomainException::class, '国家へ所属していません。');
            $rate = NationMaterialConversionRate::where('material_id', $materialId)->where('is_active', true)->first();
            throw_unless($rate, \DomainException::class, 'この素材は国家資材へ換算できません。');
            $stock = CharacterMaterial::where('character_id', $character->id)->where('material_id', $materialId)->lockForUpdate()->first();
            throw_if(! $stock || $stock->quantity < $quantity, \DomainException::class, '素材の所持数が足りません。');
            $nation = Nation::whereKey($membership->nation_id)->lockForUpdate()->firstOrFail();
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
    }

    public function spend(Nation $nation, int $points, string $type, array $metadata = [], ?int $warId = null): NationResourceTransaction
    {
        throw_if($points < 1, \DomainException::class, '消費ポイントが不正です。');
        $locked = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
        throw_if($locked->treasury_points < $points, \DomainException::class, '国家資材が足りません。');
        $locked->treasury_points -= $points;
        $locked->save();

        return NationResourceTransaction::create([
            'nation_id' => $locked->id, 'nation_war_id' => $warId, 'transaction_type' => $type,
            'points_delta' => -$points, 'balance_after' => $locked->treasury_points, 'metadata' => $metadata,
        ]);
    }

    public function credit(Nation $nation, int $points, string $type, array $metadata = [], ?int $warId = null): NationResourceTransaction
    {
        $locked = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
        $locked->treasury_points += max(0, $points);
        $locked->save();
        return NationResourceTransaction::create(['nation_id' => $locked->id, 'nation_war_id' => $warId, 'transaction_type' => $type, 'points_delta' => max(0, $points), 'balance_after' => $locked->treasury_points, 'metadata' => $metadata]);
    }
}
