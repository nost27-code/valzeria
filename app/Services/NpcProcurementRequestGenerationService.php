<?php

namespace App\Services;

use App\Models\City;
use App\Models\NpcProcurementRequest;
use App\Models\NpcProcurementRequestMaterial;
use App\Models\NpcProcurementRequestTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NpcProcurementRequestGenerationService
{
    private const COMMON_TARGET_COUNT = 5;
    private const ACTIVE_REQUEST_LIMIT = 10;

    public function generateDailyRequests(?Carbon $date = null, bool $force = false): array
    {
        $date ??= now();
        $batchKey = $date->toDateString() . '-daily';

        try {
            return DB::transaction(function () use ($date, $batchKey, $force): array {
                $expired = $this->expireOldRequests();
                $activeCount = $this->activeRequestCount();

                if ($activeCount >= self::ACTIVE_REQUEST_LIMIT && ! $force) {
                    return [
                        'generated' => 0,
                        'expired' => $expired,
                        'active_count' => $activeCount,
                        'reason' => 'active request limit reached',
                    ];
                }

                $targetCount = $force
                    ? self::COMMON_TARGET_COUNT
                    : min(self::COMMON_TARGET_COUNT, self::ACTIVE_REQUEST_LIMIT - $activeCount);
                if ($targetCount <= 0) {
                    return [
                        'generated' => 0,
                        'expired' => $expired,
                        'active_count' => $activeCount,
                        'reason' => 'no request slots available',
                    ];
                }

                $generated = $this->generateForCity(null, $targetCount, $date, $batchKey);

                Log::info('NPC procurement requests generated', [
                    'date' => $date->toDateString(),
                    'batch_key' => $batchKey,
                    'generated_count' => $generated,
                    'expired_count' => $expired,
                ]);

                return [
                    'generated' => $generated,
                    'expired' => $expired,
                    'active_count' => $this->activeRequestCount(),
                    'batch_key' => $batchKey,
                ];
            }, 3);
        } catch (Throwable $e) {
            Log::error('Failed to generate NPC procurement requests', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function expireOldRequests(): int
    {
        return NpcProcurementRequest::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    private function generateForCity(?City $city, int $targetCount, Carbon $date, string $batchKey): int
    {
        $templates = $this->pickTemplates($city, $targetCount, $date);
        $generated = 0;

        foreach ($templates as $template) {
            $this->createRequestFromTemplate($template, $date, $batchKey);
            $generated++;
        }

        return $generated;
    }

    private function pickTemplates(?City $city, int $count, Carbon $date): Collection
    {
        $activeTemplateIds = NpcProcurementRequest::query()
            ->whereNotNull('npc_procurement_request_template_id')
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->pluck('npc_procurement_request_template_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $templates = NpcProcurementRequestTemplate::query()
            ->active()
            ->where('frequency_weight', '>', 0)
            ->where('city_id', $city?->id)
            ->whereHas('materials')
            ->whereNotIn('id', $activeTemplateIds)
            ->with('materials.material')
            ->get();

        return $this->weightedRandomPick($templates, $count);
    }

    private function createRequestFromTemplate(
        NpcProcurementRequestTemplate $template,
        Carbon $date,
        string $batchKey
    ): NpcProcurementRequest {
        $request = NpcProcurementRequest::create([
            'npc_procurement_request_template_id' => $template->id,
            'city_id' => $template->city_id,
            'title' => $template->title,
            'requester_name' => $template->requester_name,
            'requester_type' => $template->requester_type,
            'description' => $template->description,
            'purpose_label' => $template->purpose_label,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addHours(max(1, (int) $template->duration_hours)),
            'completed_at' => null,
            'reward_gold_on_complete' => $template->reward_gold_on_complete,
            'reward_association_point_on_complete' => $template->reward_association_point_on_complete,
            'reward_items_json' => $template->reward_items_json,
            'display_order' => $template->display_order,
            'generated_for_date' => $date->toDateString(),
            'generated_batch_key' => $batchKey,
        ]);

        foreach ($template->materials as $templateMaterial) {
            NpcProcurementRequestMaterial::create([
                'npc_procurement_request_id' => $request->id,
                'material_id' => $templateMaterial->material_id,
                'required_quantity' => $templateMaterial->required_quantity,
                'delivered_quantity' => 0,
                'reward_gold_per_unit' => $templateMaterial->reward_gold_per_unit,
            ]);
        }

        return $request;
    }

    private function weightedRandomPick(Collection $templates, int $count): Collection
    {
        $picked = collect();
        $pool = $templates->values();

        while ($picked->count() < $count && $pool->isNotEmpty()) {
            $totalWeight = max(1, (int) $pool->sum('frequency_weight'));
            $roll = random_int(1, $totalWeight);
            $current = 0;

            foreach ($pool as $index => $template) {
                $current += (int) $template->frequency_weight;
                if ($roll <= $current) {
                    $picked->push($template);
                    $pool->forget($index);
                    $pool = $pool->values();
                    break;
                }
            }
        }

        return $picked;
    }

    private function activeRequestCount(): int
    {
        return NpcProcurementRequest::query()
            ->activeNow()
            ->count();
    }
}
