<?php

namespace App\Services;

use App\Models\Character;
use App\Models\NpcProcurementRequestMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class HomeActionService
{
    public function __construct(
        private readonly CharacterNotificationService $notificationService,
        private readonly CharacterStatusService $statusService,
        private readonly DailySupplyService $dailySupplyService,
        private readonly AreaService $areaService,
        private readonly EquipmentEvolutionService $equipmentEvolutionService,
        private readonly JobArtService $jobArtService
    ) {
    }

    public function getActions(Character $character, int $limit = 4): array
    {
        $actions = collect();

        $this->appendExplorationBagAction($actions, $character);
        $this->appendBonusPointAction($actions, $character);
        $this->appendDailySupplyAction($actions, $character);
        $this->appendEquipmentEvolutionAction($actions, $character);
        $this->appendJobArtSetupAction($actions, $character);
        $this->appendNpcRequestAction($actions, $character);
        $this->appendMarketNotificationAction($actions, $character);
        $this->appendExplorationStartAction($actions, $character);
        $this->appendRecoveryAction($actions, $character);

        return $actions
            ->sortByDesc('priority')
            ->take($limit)
            ->values()
            ->all();
    }

    public function marketActionCount(Character $character): int
    {
        return $this->notificationService->unreadCountByCategory($character, 'market')
            + $this->unseenDeliverableNpcRequestCount($character);
    }

    public function unseenDeliverableNpcRequestCount(Character $character): int
    {
        $deliverableCount = $this->deliverableNpcRequestCount($character);
        $seenCount = (int) session($this->npcRequestSeenSessionKey($character), 0);

        return max(0, $deliverableCount - $seenCount);
    }

    public function markDeliverableNpcRequestsSeen(Character $character): void
    {
        session([$this->npcRequestSeenSessionKey($character) => $this->deliverableNpcRequestCount($character)]);
    }

    public function deliverableNpcRequestCount(Character $character): int
    {
        if (! Schema::hasTable('npc_procurement_request_materials')
            || ! Schema::hasTable('npc_procurement_requests')
            || ! Schema::hasTable('character_materials')) {
            return 0;
        }

        return (int) NpcProcurementRequestMaterial::query()
            ->join('npc_procurement_requests', 'npc_procurement_requests.id', '=', 'npc_procurement_request_materials.npc_procurement_request_id')
            ->join('character_materials', function ($join) use ($character) {
                $join->on('character_materials.material_id', '=', 'npc_procurement_request_materials.material_id')
                    ->where('character_materials.character_id', '=', $character->id)
                    ->where('character_materials.quantity', '>', 0);
            })
            ->where('npc_procurement_requests.status', 'active')
            ->where('npc_procurement_requests.starts_at', '<=', now())
            ->where('npc_procurement_requests.expires_at', '>', now())
            ->whereColumn('npc_procurement_request_materials.delivered_quantity', '<', 'npc_procurement_request_materials.required_quantity')
            ->count();
    }

    private function appendBonusPointAction(Collection $actions, Character $character): void
    {
        $bp = (int) ($character->bonus_points ?? 0);
        if ($bp <= 0) {
            return;
        }

        $actions->push([
            'key' => 'bonus_points_available',
            'title' => "未使用BPが{$bp}あります",
            'body' => '能力を割り振って冒険者を強化できます。',
            'action_label' => '割り振る',
            'action_url' => route('bonus-points.index'),
            'icon' => '✦',
            'priority' => 90,
            'category' => 'growth',
        ]);
    }

    private function appendNpcRequestAction(Collection $actions, Character $character): void
    {
        $count = $this->deliverableNpcRequestCount($character);
        if ($count <= 0) {
            return;
        }

        $actions->push([
            'key' => 'npc_request_deliverable',
            'title' => '納品できる依頼があります',
            'body' => '所持素材で納品できる調達依頼が' . number_format($count) . '件あります。',
            'action_label' => '依頼を見る',
            'action_url' => route('market.npc-requests.index'),
            'icon' => '📋',
            'priority' => 80,
            'category' => 'market',
        ]);
    }

    private function appendMarketNotificationAction(Collection $actions, Character $character): void
    {
        $count = $this->notificationService->unreadCountByCategory($character, 'market');
        if ($count <= 0) {
            return;
        }

        $actions->push([
            'key' => 'market_notification_unread',
            'title' => '市場で素材が売れました',
            'body' => '売却完了した素材があります。',
            'action_label' => '履歴を見る',
            'action_url' => route('market.index', ['tab' => 'history']),
            'icon' => '⚖️',
            'icon_image' => 'icon/icon_032.webp',
            'priority' => 70,
            'category' => 'market',
        ]);
    }

    private function appendRecoveryAction(Collection $actions, Character $character): void
    {
        $stats = $this->statusService->getFinalStats($character);
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base ?? 1));
        $maxSp = max(1, (int) ($stats['max_mp'] ?? $character->mp_base ?? 1));
        $hpRate = (int) ($character->current_hp ?? 0) / $maxHp;
        $spRate = (int) ($character->current_mp ?? 0) / $maxSp;

        if ($hpRate > 0.35 && $spRate > 0.25) {
            return;
        }

        $actions->push([
            'key' => 'recovery_recommended',
            'title' => 'HP/SPが減っています',
            'body' => '街の宿屋で回復してから探索すると安定します。',
            'action_label' => '街を見る',
            'tab' => 'town',
            'icon' => '🛏️',
            'icon_image' => 'icon/icon_018.webp',
            'priority' => 50,
            'category' => 'town',
        ]);
    }

    private function appendDailySupplyAction(Collection $actions, Character $character): void
    {
        if (! Schema::hasTable('character_item_daily_supplies')) {
            return;
        }

        $supplyStatuses = collect($this->dailySupplyService->statusFor($character));
        $claimableItems = $supplyStatuses
            ->filter(fn (array $entry) => (int) ($entry['owned_count'] ?? 0) < (int) ($entry['target_count'] ?? 10)
                && (int) ($entry['claimable_count'] ?? 0) > 0)
            ->values();

        if ($claimableItems->isEmpty()) {
            return;
        }

        $itemNames = $claimableItems
            ->map(fn (array $entry) => ($entry['name'] ?? '回復アイテム') . ' +' . number_format((int) ($entry['claimable_count'] ?? 0)))
            ->implode(' / ');

        $actions->push([
            'key' => 'daily_supply_available',
            'title' => '補給所に行って回復アイテムを受け取ろう',
            'body' => $itemNames !== '' ? "受け取れる分: {$itemNames}" : '探索用の回復アイテムを補給できます。',
            'action_label' => '補給所へ',
            'action_url' => route('shop.items'),
            'icon' => '✚',
            'icon_image' => 'icon/icon_044.webp',
            'priority' => 85,
            'category' => 'town',
        ]);
    }

    private function appendEquipmentEvolutionAction(Collection $actions, Character $character): void
    {
        if (! Schema::hasTable('weapon_evolution_recipes')
            || ! Schema::hasTable('armor_evolution_recipes')
            || ! Schema::hasTable('character_items')) {
            return;
        }

        try {
            $equippedCandidates = collect($this->equipmentEvolutionService->candidates($character))
                ->filter(fn (array $candidate) => (bool) ($candidate['has_equipped_source'] ?? false))
                ->values();
        } catch (\Throwable $exception) {
            report($exception);
            return;
        }

        $readyCandidate = $equippedCandidates
            ->first(fn (array $candidate) => (bool) ($candidate['can_evolve'] ?? false));

        if ($readyCandidate) {
            $actions->push([
                'key' => 'equipment_evolution_ready',
                'title' => '装備品を合成しよう',
                'body' => $this->equipmentEvolutionBody($readyCandidate, '合成屋で実行できます。'),
                'action_label' => '合成屋へ',
                'action_url' => route('smith.index'),
                'icon' => '✦',
                'icon_image' => 'icon/icon_034.webp',
                'priority' => 78,
                'category' => 'growth',
            ]);
            return;
        }

        $nearCandidate = $equippedCandidates
            ->filter(fn (array $candidate) => $this->nearEquipmentEvolutionMaterialMissingCount($candidate) > 0
                && $this->nearEquipmentEvolutionMaterialMissingCount($candidate) <= 3
                && (int) ($candidate['missing_equipment_count'] ?? 0) <= 0
                && (int) ($candidate['missing_gold'] ?? 0) <= 0)
            ->sortBy(fn (array $candidate) => $this->nearEquipmentEvolutionMaterialMissingCount($candidate))
            ->first();

        if (! $nearCandidate) {
            return;
        }

        $missingMaterials = $this->missingEvolutionMaterials($nearCandidate);
        $materialName = $missingMaterials[0]['name'] ?? '素材';
        $missingCount = $this->nearEquipmentEvolutionMaterialMissingCount($nearCandidate);
        $titleMaterialName = count($missingMaterials) > 1 ? "{$materialName}など" : $materialName;

        $actions->push([
            'key' => 'equipment_evolution_near',
            'title' => "{$titleMaterialName}を集めて、装備品を合成してみよう",
            'body' => $this->equipmentEvolutionBody($nearCandidate, 'あと' . number_format($missingCount) . '個で合成できます。'),
            'action_label' => '探索へ',
            'tab' => 'dungeon',
            'icon' => '✦',
            'icon_image' => 'icon/icon_034.webp',
            'priority' => 68,
            'category' => 'growth',
        ]);
    }

    private function appendJobArtSetupAction(Collection $actions, Character $character): void
    {
        if (! Schema::hasTable('character_job_art_slots') || ! Schema::hasTable('skills')) {
            return;
        }

        try {
            $availableArts = $this->jobArtService->availableArts($character, 'pve');
            $selectedSlots = $this->jobArtService->selectedSlots($character, 'pve');
        } catch (\Throwable $exception) {
            report($exception);
            return;
        }

        $availableCount = $availableArts->count();
        if ($availableCount <= 0) {
            return;
        }

        $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();
        $selectedCount = $selectedSkills->count();
        $totalCost = $this->jobArtService->totalCost($selectedSkills);
        $signature = $this->jobArtService->setupSignature($character, $availableArts, $selectedSlots);
        $seenSignature = (string) session($this->jobArtService->setupSeenSessionKey($character), '');

        $isComplete = $availableCount <= 2
            ? $selectedCount >= $availableCount
            : $selectedCount >= JobArtService::MAX_SLOTS
                || $totalCost >= JobArtService::MAX_COST
                || ($selectedCount >= 2 && hash_equals($signature, $seenSignature));

        if ($isComplete) {
            return;
        }

        $body = $selectedCount <= 0
            ? '習得済みの奥義があります。戦闘で使えるようにセットしましょう。'
            : '現在 ' . $selectedCount . '枠 / Cost' . $totalCost . '。空き枠やCostを活かせます。';

        $actions->push([
            'key' => 'job_art_setup_recommended',
            'title' => '奥義をセットしよう',
            'body' => $body,
            'action_label' => '奥義へ',
            'action_url' => route('job-arts.index'),
            'icon' => '✦',
            'icon_image' => 'icon/icon_041.webp',
            'priority' => 88,
            'category' => 'growth',
        ]);
    }

    private function equipmentEvolutionBody(array $candidate, string $suffix): string
    {
        $from = (string) ($candidate['from_name'] ?? '装備品');
        $to = (string) ($candidate['to_display_name'] ?? $candidate['to_name'] ?? '上位装備');

        return "{$from}を{$to}へ進化合成できます。{$suffix}";
    }

    private function nearEquipmentEvolutionMaterialMissingCount(array $candidate): int
    {
        return collect($this->missingEvolutionMaterials($candidate))
            ->sum(fn (array $material) => (int) ($material['missing'] ?? 0));
    }

    private function missingEvolutionMaterials(array $candidate): array
    {
        $materials = collect($candidate['required_materials'] ?? []);
        if (! empty($candidate['evolution_stone_requirement'])
            && ! (bool) ($candidate['can_use_evolution_stone'] ?? false)) {
            $materials->push($candidate['evolution_stone_requirement']);
        }

        return $materials
            ->filter(fn (array $material) => (int) ($material['missing'] ?? 0) > 0)
            ->sortByDesc(fn (array $material) => (int) ($material['missing'] ?? 0))
            ->values()
            ->all();
    }

    private function appendExplorationStartAction(Collection $actions, Character $character): void
    {
        $area = collect($this->areaService->getAreasWithProgress($character))
            ->filter(fn ($area) => (bool) ($area->is_unlocked ?? false)
                && (bool) ($area->meets_job_requirements ?? true)
                && ! $this->isExploreHiddenArea($area))
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->first(fn ($area) => ! $this->isAreaFullyHandled($area));

        $area ??= collect($this->areaService->getAreasWithProgress($character))
            ->filter(fn ($area) => (bool) ($area->is_unlocked ?? false)
                && (bool) ($area->meets_job_requirements ?? true)
                && ! $this->isExploreHiddenArea($area))
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        if (!$area) {
            return;
        }

        $actions->push([
            'key' => 'exploration_start',
            'title' => '探索しよう',
            'body' => "{$area->name}へ向かい、経験値や素材を集めましょう。",
            'action_label' => '探索へ',
            'tab' => 'dungeon',
            'target_area_id' => (int) $area->id,
            'icon' => '🧭',
            'icon_image' => 'icon/icon_002.webp',
            'priority' => 65,
            'category' => 'dungeon',
        ]);
    }

    private function isAreaFullyHandled($area): bool
    {
        $requiredPoint = max(100, (int) ($area->development_required_point ?? 100));
        $progressPoint = (int) ($area->development_point ?? 0);

        return $progressPoint >= $requiredPoint
            && (bool) ($area->boss_defeated ?? false);
    }

    private function isExploreHiddenArea($area): bool
    {
        $areaId = (int) ($area->id ?? 0);

        return ! empty($area->hide_explore)
            || ($areaId >= 71 && $areaId <= 74);
    }

    private function appendExplorationBagAction(Collection $actions, Character $character): void
    {
        if (! Schema::hasTable('character_exploration_states')) {
            return;
        }

        $state = $character->explorationState;
        if (! $state || ((int) ($state->chain_count ?? 0) <= 0 && (int) ($state->exploration_point ?? 0) <= 0)) {
            return;
        }

        $actions->push([
            'key' => 'exploration_progress_active',
            'title' => '探索が進行中です',
            'body' => '探索度や連戦が残っています。続けるか、街に戻って整えましょう。',
            'action_label' => '再開する',
            'action_url' => route('battle.resume'),
            'icon' => '🧭',
            'icon_image' => 'icon/icon_002.webp',
            'priority' => 100,
            'category' => 'dungeon',
        ]);
    }

    private function npcRequestSeenSessionKey(Character $character): string
    {
        return 'market_npc_request_seen_count_' . $character->id;
    }
}
