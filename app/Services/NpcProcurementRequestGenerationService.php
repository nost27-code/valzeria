<?php

namespace App\Services;

use App\Models\City;
use App\Models\NpcMaster;
use App\Models\NpcProcurementRequest;
use App\Models\NpcProcurementRequestMaterial;
use App\Models\NpcProcurementRequestTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NpcProcurementRequestGenerationService
{
    private const COMMON_TARGET_COUNT = 5;
    private const ACTIVE_REQUEST_LIMIT = 10;
    private const PERSISTENT_DURATION_HOURS = 87600;
    private const PERSISTENT_TEMPLATE_DEFINITIONS = [
        [
            'title' => '旅装ギルド設立準備',
            'requester_name' => '旅装ギルド準備係',
            'requester_type' => 'guild',
            'description' => '新しい遠征路に備え、軽くて丈夫な旅装を仕立てるための布材を集めています。',
            'purpose_label' => '新施設準備',
            'frequency_weight' => 65,
            'display_order' => 110,
            'materials' => [
                ['5025', 1000, 25],
                ['5027', 1000, 25],
                ['5029', 1000, 30],
                ['5031', 1000, 30],
            ],
        ],
        [
            'title' => '遠征外套の試作',
            'requester_name' => '裁縫職人ミラ',
            'requester_type' => 'artisan',
            'description' => '長い探索に耐える外套を試作しています。各地の繊維素材を譲ってください。',
            'purpose_label' => '外套試作',
            'frequency_weight' => 55,
            'display_order' => 120,
            'materials' => [
                ['5033', 1200, 35],
                ['5035', 1200, 35],
                ['5037', 1200, 40],
            ],
        ],
        [
            'title' => '祭礼布と護符の修繕',
            'requester_name' => '神殿の司祭',
            'requester_type' => 'temple',
            'description' => '古い祭礼布と護符を修繕するため、守護や精霊にまつわる布材を探しています。',
            'purpose_label' => '儀式準備',
            'frequency_weight' => 45,
            'display_order' => 130,
            'materials' => [
                ['5026', 900, 35],
                ['5030', 900, 40],
                ['5034', 900, 45],
            ],
        ],
        [
            'title' => '深層調査隊の防護布',
            'requester_name' => '深層調査隊',
            'requester_type' => 'expedition',
            'description' => '熱、瘴気、闇に耐える防護布の準備を進めています。まだ行き先は伏せられています。',
            'purpose_label' => '調査準備',
            'frequency_weight' => 40,
            'display_order' => 140,
            'materials' => [
                ['5032', 1000, 40],
                ['5039', 1000, 40],
                ['5040', 1000, 45],
            ],
        ],
        [
            'title' => '空織り調査隊の装備準備',
            'requester_name' => '空織り調査隊',
            'requester_type' => 'expedition',
            'description' => '高空域の調査に向け、強い魔力や竜の気配に耐える布材を集めています。',
            'purpose_label' => '新ダンジョン準備',
            'frequency_weight' => 35,
            'display_order' => 150,
            'materials' => [
                ['5041', 1500, 45],
                ['5042', 1500, 50],
                ['5043', 1500, 50],
                ['5044', 1500, 50],
            ],
        ],
    ];

    public function generateDailyRequests(?Carbon $date = null, bool $force = false): array
    {
        $date ??= now();
        $batchKey = $date->toDateString() . '-daily';

        try {
            return DB::transaction(function () use ($date, $batchKey, $force): array {
                $expired = $this->expireOldRequests();
                $this->ensurePersistentRequestTemplates();
                $persistentGenerated = $this->createMissingPersistentRequests($date, $batchKey);
                $activeCount = $this->activeRequestCount();

                if ($activeCount >= self::ACTIVE_REQUEST_LIMIT && ! $force) {
                    return [
                        'generated' => $persistentGenerated,
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
                $generated += $persistentGenerated;

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
            ->whereNotIn('title', NpcProcurementRequest::PERSISTENT_UNTIL_COMPLETED_TITLES)
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

    public function ensurePersistentRequests(?Carbon $date = null, ?string $batchKey = null): int
    {
        $date ??= now();
        $batchKey ??= $date->toDateString() . '-persistent';

        return DB::transaction(
            function () use ($date, $batchKey): int {
                $this->ensurePersistentRequestTemplates();

                return $this->createMissingPersistentRequests($date, $batchKey);
            },
            3
        );
    }

    private function ensurePersistentRequestTemplates(): void
    {
        if (
            ! Schema::hasTable('npc_procurement_request_templates')
            || ! Schema::hasTable('npc_procurement_request_template_materials')
            || ! Schema::hasTable('materials')
        ) {
            return;
        }

        foreach (self::PERSISTENT_TEMPLATE_DEFINITIONS as $definition) {
            $materials = $definition['materials'];
            unset($definition['materials']);

            DB::table('npc_procurement_request_templates')->updateOrInsert(
                [
                    'title' => $definition['title'],
                    'requester_name' => $definition['requester_name'],
                ],
                array_merge($definition, [
                    'city_id' => null,
                    'npc_id' => null,
                    'min_character_level' => 1,
                    'max_character_level' => null,
                    'duration_hours' => self::PERSISTENT_DURATION_HOURS,
                    'reward_gold_on_complete' => 0,
                    'reward_association_point_on_complete' => 0,
                    'reward_items_json' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );

            $templateId = (int) DB::table('npc_procurement_request_templates')
                ->where('title', $definition['title'])
                ->where('requester_name', $definition['requester_name'])
                ->value('id');

            if ($templateId <= 0) {
                continue;
            }

            $keptMaterialIds = [];
            foreach ($materials as [$materialCode, $requiredQuantity, $rewardGoldPerUnit]) {
                $materialId = (int) DB::table('materials')
                    ->where('material_code', $materialCode)
                    ->value('id');

                if ($materialId <= 0) {
                    continue;
                }

                DB::table('npc_procurement_request_template_materials')->updateOrInsert(
                    [
                        'npc_procurement_request_template_id' => $templateId,
                        'material_id' => $materialId,
                    ],
                    [
                        'required_quantity' => (int) $requiredQuantity,
                        'reward_gold_per_unit' => (int) $rewardGoldPerUnit,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $keptMaterialIds[] = $materialId;
            }

            if ($keptMaterialIds !== []) {
                DB::table('npc_procurement_request_template_materials')
                    ->where('npc_procurement_request_template_id', $templateId)
                    ->whereNotIn('material_id', $keptMaterialIds)
                    ->delete();
            }
        }
    }

    private function createMissingPersistentRequests(Carbon $date, string $batchKey): int
    {
        $existingTemplateIds = NpcProcurementRequest::query()
            ->whereNotNull('npc_procurement_request_template_id')
            ->whereIn('title', NpcProcurementRequest::PERSISTENT_UNTIL_COMPLETED_TITLES)
            ->whereIn('status', ['active', 'completed'])
            ->pluck('npc_procurement_request_template_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $templates = NpcProcurementRequestTemplate::query()
            ->active()
            ->whereIn('title', NpcProcurementRequest::PERSISTENT_UNTIL_COMPLETED_TITLES)
            ->whereHas('materials')
            ->when($existingTemplateIds !== [], fn ($query) => $query->whereNotIn('id', $existingTemplateIds))
            ->with(['materials.material', 'npc'])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

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
            ->where(function ($query) {
                $query->where('expires_at', '>', now())
                    ->orWhereIn('title', NpcProcurementRequest::PERSISTENT_UNTIL_COMPLETED_TITLES);
            })
            ->pluck('npc_procurement_request_template_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $completedPersistentTemplateIds = NpcProcurementRequest::query()
            ->whereNotNull('npc_procurement_request_template_id')
            ->where('status', 'completed')
            ->whereIn('title', NpcProcurementRequest::PERSISTENT_UNTIL_COMPLETED_TITLES)
            ->pluck('npc_procurement_request_template_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $activeNpcIds = NpcProcurementRequest::query()
            ->whereNotNull('npc_id')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('expires_at', '>', now())
                    ->orWhereIn('title', NpcProcurementRequest::PERSISTENT_UNTIL_COMPLETED_TITLES);
            })
            ->pluck('npc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $templates = NpcProcurementRequestTemplate::query()
            ->active()
            ->where('frequency_weight', '>', 0)
            ->where('city_id', $city?->id)
            ->whereHas('materials')
            ->whereNotIn('id', array_values(array_unique(array_merge($activeTemplateIds, $completedPersistentTemplateIds))))
            ->with(['materials.material', 'npc'])
            ->get();

        return $this->weightedRandomPick($templates, $count)
            ->map(function (NpcProcurementRequestTemplate $template) use (&$activeNpcIds) {
                $npc = $template->npc ?: $this->pickNpc($activeNpcIds);
                if ($npc) {
                    $activeNpcIds[] = (int) $npc->npc_id;
                    $template->setRelation('assignedNpc', $npc);
                }

                return $template;
            });
    }

    private function createRequestFromTemplate(
        NpcProcurementRequestTemplate $template,
        Carbon $date,
        string $batchKey
    ): NpcProcurementRequest {
        $npc = $template->relationLoaded('assignedNpc')
            ? $template->getRelation('assignedNpc')
            : $template->npc;

        $request = NpcProcurementRequest::create([
            'npc_procurement_request_template_id' => $template->id,
            'city_id' => $template->city_id,
            'npc_id' => $npc?->npc_id,
            'title' => $template->title,
            'requester_name' => $npc?->npc_name ?? $template->requester_name,
            'requester_type' => $npc ? 'npc' : $template->requester_type,
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

    private function pickNpc(array $excludeNpcIds): ?NpcMaster
    {
        $query = NpcMaster::query()
            ->where('is_active', true);

        if ($excludeNpcIds !== []) {
            $query->whereNotIn('npc_id', $excludeNpcIds);
        }

        $pool = $query
            ->orderBy('sort_order')
            ->get();

        if ($pool->isEmpty()) {
            $pool = NpcMaster::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        if ($pool->isEmpty()) {
            return null;
        }

        $weighted = $pool->map(fn (NpcMaster $npc) => [
            'npc' => $npc,
            'weight' => max(1, (int) ($npc->base_weight ?? 1)),
        ]);
        $roll = random_int(1, max(1, (int) $weighted->sum('weight')));
        $cursor = 0;

        foreach ($weighted as $entry) {
            $cursor += (int) $entry['weight'];
            if ($roll <= $cursor) {
                return $entry['npc'];
            }
        }

        return $pool->first();
    }
}
