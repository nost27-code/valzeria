<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\NpcMaterialStock;
use App\Models\NpcProcurementDelivery;
use App\Models\NpcProcurementRequest;
use App\Models\NpcProcurementRequestMaterial;
use App\Models\Material;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NpcProcurementRequestService
{
    public function __construct(private readonly GoldService $goldService)
    {
    }

    public function getActiveRequests(?Character $character = null): Collection
    {
        $requests = NpcProcurementRequest::query()
            ->activeNow()
            ->with(['materials.material', 'city', 'npc'])
            ->orderBy('display_order')
            ->orderBy('expires_at')
            ->get();

        if ($character) {
            $this->attachDeliveryContext($requests, $character);
        }

        return $requests;
    }

    public function getActiveRequestsForMaterial(Material $material, ?Character $character = null, int $limit = 3): Collection
    {
        $requests = NpcProcurementRequest::query()
            ->activeNow()
            ->whereHas('materials', function ($query) use ($material) {
                $query->where('material_id', $material->id)
                    ->whereColumn('delivered_quantity', '<', 'required_quantity');
            })
            ->with(['materials' => function ($query) use ($material) {
                $query->where('material_id', $material->id)->with('material');
            }, 'city', 'npc'])
            ->orderBy('display_order')
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        if ($character) {
            $this->attachDeliveryContext($requests, $character);
        }

        return $requests;
    }

    public function attachDeliveryContext(Collection $requests, Character $character): void
    {
        $materialIds = $requests
            ->flatMap(fn (NpcProcurementRequest $request) => $request->materials->pluck('material_id'))
            ->unique()
            ->values();

        $ownedByMaterial = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->whereIn('material_id', $materialIds)
            ->pluck('quantity', 'material_id');

        foreach ($requests as $request) {
            foreach ($request->materials as $requestMaterial) {
                $owned = (int) ($ownedByMaterial[$requestMaterial->material_id] ?? 0);
                $requestMaterial->setAttribute('owned_quantity', $owned);
                $requestMaterial->setAttribute('deliverable_quantity', min($owned, $requestMaterial->remainingQuantity()));
            }
        }
    }

    public function getActiveRequestCountsByMaterial(): array
    {
        return NpcProcurementRequestMaterial::query()
            ->join('npc_procurement_requests', 'npc_procurement_requests.id', '=', 'npc_procurement_request_materials.npc_procurement_request_id')
            ->where('npc_procurement_requests.status', 'active')
            ->where('npc_procurement_requests.starts_at', '<=', now())
            ->where('npc_procurement_requests.expires_at', '>', now())
            ->whereColumn('npc_procurement_request_materials.delivered_quantity', '<', 'npc_procurement_request_materials.required_quantity')
            ->select('npc_procurement_request_materials.material_id', DB::raw('COUNT(*) as request_count'))
            ->groupBy('npc_procurement_request_materials.material_id')
            ->pluck('request_count', 'material_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function deliver(Character $character, int $requestMaterialId, int $quantity): NpcProcurementDelivery
    {
        $quantity = max(1, $quantity);

        return DB::transaction(function () use ($character, $requestMaterialId, $quantity) {
            $character = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $requestMaterial = NpcProcurementRequestMaterial::query()
                ->whereKey($requestMaterialId)
                ->lockForUpdate()
                ->firstOrFail();
            $request = NpcProcurementRequest::query()
                ->whereKey($requestMaterial->npc_procurement_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $request->isActive()) {
                throw ValidationException::withMessages([
                    'quantity' => 'この依頼はすでに終了しています。',
                ]);
            }

            $remaining = $requestMaterial->remainingQuantity();
            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'この素材はすでに必要数に達しています。',
                ]);
            }

            if ($quantity > $remaining) {
                throw ValidationException::withMessages([
                    'quantity' => '納品数が残り必要数を超えています。',
                ]);
            }

            $owned = CharacterMaterial::query()
                ->where('character_id', $character->id)
                ->where('material_id', $requestMaterial->material_id)
                ->lockForUpdate()
                ->first();

            if (! $owned || (int) $owned->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => '素材の所持数が不足しています。',
                ]);
            }

            $ownedRemaining = (int) $owned->quantity - $quantity;
            if ($ownedRemaining <= 0) {
                $owned->delete();
            } else {
                $owned->forceFill(['quantity' => $ownedRemaining])->save();
            }

            $requestMaterial->delivered_quantity = (int) $requestMaterial->delivered_quantity + $quantity;
            $requestMaterial->save();

            if ($request->npc_id) {
                $this->addNpcMaterialStock((int) $request->npc_id, (int) $requestMaterial->material_id, $quantity);
            }

            $baseRewardGold = $quantity * (int) $requestMaterial->reward_gold_per_unit;
            $completeRewardGold = 0;
            $completeAssociationPoint = 0;

            $allFulfilled = NpcProcurementRequestMaterial::query()
                ->where('npc_procurement_request_id', $request->id)
                ->lockForUpdate()
                ->get()
                ->every(fn (NpcProcurementRequestMaterial $material) => $material->remainingQuantity() <= 0);

            if ($allFulfilled) {
                $request->status = 'completed';
                $request->completed_at = now();
                $request->save();
                $completeRewardGold = (int) $request->reward_gold_on_complete;
                $completeAssociationPoint = (int) $request->reward_association_point_on_complete;
            }

            $totalRewardGold = $baseRewardGold + $completeRewardGold;

            $delivery = NpcProcurementDelivery::create([
                'npc_procurement_request_id' => $request->id,
                'npc_procurement_request_material_id' => $requestMaterial->id,
                'character_id' => $character->id,
                'material_id' => $requestMaterial->material_id,
                'quantity' => $quantity,
                'reward_gold' => $totalRewardGold,
                'reward_association_point' => $completeAssociationPoint,
                'created_at' => now(),
            ]);

            if ($totalRewardGold > 0) {
                $requestMaterial->loadMissing('material');
                $this->goldService->add(
                    $character,
                    $totalRewardGold,
                    'npc_procurement_delivery',
                    ($requestMaterial->material?->name ?? '素材') . " x{$quantity} を調達依頼へ納品",
                    NpcProcurementDelivery::class,
                    $delivery->id,
                    [
                        'npc_procurement_request_id' => $request->id,
                        'npc_procurement_request_material_id' => $requestMaterial->id,
                        'material_id' => $requestMaterial->material_id,
                        'quantity' => $quantity,
                    ]
                );
            }

            return $delivery->load(['request', 'material']);
        });
    }

    public function expireRequests(): int
    {
        return NpcProcurementRequest::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public function getDeliverableCount(Character $character, NpcProcurementRequestMaterial $requestMaterial): int
    {
        $ownedQuantity = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('material_id', $requestMaterial->material_id)
            ->value('quantity') ?? 0;

        return min((int) $ownedQuantity, $requestMaterial->remainingQuantity());
    }

    private function addNpcMaterialStock(int $npcId, int $materialId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $stock = NpcMaterialStock::query()
            ->where('npc_id', $npcId)
            ->where('material_id', $materialId)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            $stock->quantity = (int) $stock->quantity + $quantity;
            $stock->last_received_at = now();
            $stock->save();
            return;
        }

        NpcMaterialStock::create([
            'npc_id' => $npcId,
            'material_id' => $materialId,
            'quantity' => $quantity,
            'last_received_at' => now(),
        ]);
    }
}
