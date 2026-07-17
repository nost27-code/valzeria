<?php

namespace App\Livewire\Admin;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\KisekiTransaction;
use App\Models\StripeOrder;
use App\Models\User;
use App\Models\WeaponTraitOperationLog;
use App\Services\CharacterNotificationService;
use App\Services\CharacterStatusService;
use App\Services\ExplorationStaminaService;
use App\Services\JobService;
use App\Services\LevelService;
use App\Services\ValmonService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class UserInvestigationManager extends Component
{
    public string $userIdInput = '';
    public ?int $selectedUserId = null;
    public ?int $selectedCharacterId = null;
    public string $enemySearch = '';

    public function mount(): void
    {
        $this->userIdInput = request()->query('user_id', '');
        if ($this->userIdInput !== '') {
            $this->searchUser();
        }
    }

    public function searchUser(): void
    {
        $userId = (int) trim($this->userIdInput);
        $this->selectedUserId = $userId > 0 ? $userId : null;
        $this->selectedCharacterId = null;

        $user = $this->selectedUserId ? User::with('characters')->find($this->selectedUserId) : null;
        if ($user && $user->characters->isNotEmpty()) {
            $this->selectedCharacterId = (int) $user->characters->first()->id;
        }
    }

    public function investigateUser(int $userId): void
    {
        $this->userIdInput = (string) $userId;
        $this->searchUser();
    }

    public function selectCharacter(int $characterId): void
    {
        $this->selectedCharacterId = $characterId;
    }

    public function render()
    {
        $statusService = app(CharacterStatusService::class);
        $levelService = app(LevelService::class);
        $jobService = app(JobService::class);
        $valmonService = app(ValmonService::class);
        $staminaService = app(ExplorationStaminaService::class);

        $user = $this->selectedUserId
            ? User::with(['characters.currentJob', 'characters.currentCity', 'characters.highestCity'])->find($this->selectedUserId)
            : null;

        $character = $this->selectedCharacterId
            ? Character::query()
                ->with([
                    'user',
                    'currentJob',
                    'currentCity',
                    'highestCity',
                    'jobHistories.jobClass',
                    'areaProgresses.area.city',
                    'characterItems.item',
                    'characterMaterials.material',
                    'valmons.master',
                    'partnerValmon.master',
                    'arenaRanking',
                ])
                ->find($this->selectedCharacterId)
            : null;

        if ($user && $character && (int) $character->user_id !== (int) $user->id) {
            $character = null;
            $this->selectedCharacterId = null;
        }

        $finalStats = $character ? $statusService->getFinalStats($character) : null;
        $currentJobHistory = $character && $character->current_job_id
            ? $character->jobHistories->firstWhere('job_class_id', $character->current_job_id)
            : null;
        $nextExp = $character ? $levelService->getRequiredExp((int) $character->level) : 0;
        $jobLevel = $currentJobHistory ? (int) $currentJobHistory->job_level : 1;
        $jobExpInfo = $currentJobHistory ? $jobService->getNextLevelExp($currentJobHistory) : null;
        $valmonNextLevelRemaining = null;
        $valmonIsMaxLevel = false;
        $valmonExpPercent = 0;

        if ($character?->partnerValmon) {
            $partnerValmon = $character->partnerValmon;
            $valmonIsMaxLevel = (int) $partnerValmon->level >= ValmonService::MAX_LEVEL;
            $valmonNextLevelRemaining = $valmonService->nextLevelRemaining($partnerValmon);
            $valmonNextRequired = (int) $partnerValmon->exp + (int) ($valmonNextLevelRemaining ?? 0);
            $valmonExpPercent = $valmonIsMaxLevel
                ? 100
                : ($valmonNextRequired > 0 ? min(100, max(0, ((int) $partnerValmon->exp / $valmonNextRequired) * 100)) : 0);
        }

        $battleLogs = $character ? $this->battleLogs($character) : collect();
        $paymentLogs = $character ? $this->paymentLogs($character) : collect();
        $weaponTraitLogs = $character ? $this->weaponTraitLogs($character) : collect();
        $notifications = $character
            ? app(CharacterNotificationService::class)->latestForAdmin($character, 50)
            : collect();
        $loginLogs = $this->loginLogs($user);
        $errorLogs = $this->errorLogs($user, $character);
        $enemyCandidates = $this->enemyCandidates();
        $recentlyLoggedInUsers = $this->selectedUserId ? collect() : $this->recentlyLoggedInUsers();
        $simulationSnapshot = $character ? $this->simulationSnapshot($character, $finalStats) : [];
        $explorationStamina = $character ? $staminaService->summary($character) : null;
        $characterItems = $character?->characterItems ?? collect();
        $equipmentTypes = ['weapon', 'armor', 'accessory'];
        $equippedItems = $characterItems
            ->filter(fn ($item) => (bool) $item->is_equipped)
            ->values();
        $unequippedItems = $characterItems
            ->filter(fn ($item) => ! (bool) $item->is_equipped);
        $ownedEquipment = $unequippedItems
            ->filter(fn ($item) => in_array((string) ($item->item?->type ?? ''), $equipmentTypes, true))
            ->sortByDesc('id')
            ->take(80)
            ->values();
        $inventoryItems = $unequippedItems
            ->reject(fn ($item) => in_array((string) ($item->item?->type ?? ''), $equipmentTypes, true))
            ->groupBy('item_id')
            ->map(function (Collection $items): array {
                $latest = $items->sortByDesc('id')->first();

                return [
                    'item' => $latest?->item,
                    'quantity' => $items->count(),
                    'latest_character_item_id' => $items->max('id'),
                ];
            })
            ->sortByDesc('latest_character_item_id')
            ->take(80)
            ->values();

        return view('livewire.admin.user-investigation-manager', [
            'user' => $user,
            'character' => $character,
            'finalStats' => $finalStats,
            'currentJobHistory' => $currentJobHistory,
            'nextExp' => $nextExp,
            'jobLevel' => $jobLevel,
            'jobExpInfo' => $jobExpInfo,
            'valmonNextLevelRemaining' => $valmonNextLevelRemaining,
            'valmonIsMaxLevel' => $valmonIsMaxLevel,
            'valmonExpPercent' => $valmonExpPercent,
            'explorationStamina' => $explorationStamina,
            'equippedItems' => $equippedItems,
            'ownedEquipment' => $ownedEquipment,
            'inventoryItems' => $inventoryItems,
            'materials' => $character ? $character->characterMaterials->sortByDesc('quantity')->values() : collect(),
            'valmons' => $character ? $character->valmons->sortByDesc('level')->values() : collect(),
            'cityProgress' => $this->cityProgress($character),
            'areaProgresses' => $character ? $character->areaProgresses->sortBy(fn ($progress) => [$progress->area?->city_id ?? 9999, $progress->area_id])->values() : collect(),
            'battleLogs' => $battleLogs,
            'paymentLogs' => $paymentLogs,
            'weaponTraitLogs' => $weaponTraitLogs,
            'notifications' => $notifications,
            'loginLogs' => $loginLogs,
            'errorLogs' => $errorLogs,
            'enemyCandidates' => $enemyCandidates,
            'recentlyLoggedInUsers' => $recentlyLoggedInUsers,
            'simulationSnapshotJson' => json_encode($simulationSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ])->layout('components.layouts.admin');
    }

    private function recentlyLoggedInUsers(): Collection
    {
        if (!Schema::hasTable('player_lifecycle_events')) {
            return collect();
        }

        $lastLoginSubquery = DB::table('player_lifecycle_events')
            ->select('user_id', DB::raw('MAX(occurred_at) as last_login_at'))
            ->where('event_name', 'login')
            ->groupBy('user_id');

        return User::query()
            ->joinSub($lastLoginSubquery, 'last_logins', fn ($join) => $join->on('users.id', '=', 'last_logins.user_id'))
            ->select('users.*', 'last_logins.last_login_at')
            ->withCasts(['last_login_at' => 'datetime'])
            ->with('characters')
            ->orderByDesc('last_logins.last_login_at')
            ->orderByDesc('users.id')
            ->limit(60)
            ->get();
    }

    private function battleLogs(Character $character): Collection
    {
        if (!Schema::hasTable('battle_logs')) {
            return collect();
        }

        return BattleLog::query()
            ->with(['area.city', 'enemy'])
            ->where('character_id', $character->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    private function paymentLogs(Character $character): Collection
    {
        $logs = collect();

        if (Schema::hasTable('stripe_orders')) {
            $logs = $logs->merge(StripeOrder::query()
                ->where('character_id', $character->id)
                ->orderByDesc(DB::raw('COALESCE(fulfilled_at, created_at)'))
                ->limit(30)
                ->get()
                ->map(fn ($order) => [
                    'occurred_at' => $order->fulfilled_at ?? $order->created_at,
                    'type' => 'Stripe注文',
                    'summary' => ($order->pack_key ?? '-') . ' / ' . number_format((int) $order->kiseki_amount) . '輝石',
                    'detail' => number_format((int) $order->price_jpy) . '円 / ' . $order->status . ' / ' . $order->session_id,
                ]));
        }

        if (Schema::hasTable('kiseki_transactions')) {
            $logs = $logs->merge(KisekiTransaction::query()
                ->where('character_id', $character->id)
                ->whereIn('transaction_type', ['purchase', 'manual', 'manual_grant', 'admin_grant', 'adjustment', 'shop_purchase'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($tx) => [
                    'occurred_at' => $tx->created_at,
                    'type' => '輝石取引',
                    'summary' => ($tx->amount >= 0 ? '+' : '') . number_format((int) $tx->amount) . ' / ' . $tx->kiseki_type,
                    'detail' => trim(($tx->transaction_type ?? '-') . ' / ' . ($tx->source_type ?? '-') . ' / ' . ($tx->description ?? '-')),
                ]));
        }

        if (Schema::hasTable('stripe_payment_audits')) {
            $logs = $logs->merge(DB::table('stripe_payment_audits')
                ->where('character_id', $character->id)
                ->orderByDesc(DB::raw('COALESCE(webhook_received_at, created_at)'))
                ->limit(50)
                ->get()
                ->map(fn ($audit) => [
                    'occurred_at' => $audit->webhook_received_at ?? $audit->created_at,
                    'type' => 'Stripe監査',
                    'summary' => ($audit->status ?? '-') . ' / ' . ($audit->product_name ?? $audit->pack_key ?? '-'),
                    'detail' => trim(($audit->stripe_session_id ?? $audit->stripe_payment_intent_id ?? $audit->stripe_charge_id ?? '-') . ' / ' . ($audit->error_message ?? '')),
                ]));
        }

        return $logs->sortByDesc('occurred_at')->take(80)->values();
    }

    private function weaponTraitLogs(Character $character): Collection
    {
        if (!Schema::hasTable('weapon_trait_operation_logs')) {
            return collect();
        }

        return WeaponTraitOperationLog::query()
            ->where('character_id', $character->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    private function loginLogs(?User $user): Collection
    {
        if (!$user) {
            return collect();
        }

        foreach (['login_logs', 'user_login_logs', 'sessions'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            return DB::table($table)
                ->where('user_id', $user->id)
                ->orderByDesc(Schema::hasColumn($table, 'created_at') ? 'created_at' : 'id')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'occurred_at' => $row->created_at ?? $row->last_activity ?? null,
                    'summary' => $row->ip_address ?? $row->ip ?? 'login',
                    'detail' => $row->user_agent ?? $row->payload ?? '-',
                ]);
        }

        return collect();
    }

    private function errorLogs(?User $user, ?Character $character): Collection
    {
        foreach (['error_logs', 'user_error_logs', 'admin_error_logs'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);
            if ($user && Schema::hasColumn($table, 'user_id')) {
                $query->where('user_id', $user->id);
            } elseif ($character && Schema::hasColumn($table, 'character_id')) {
                $query->where('character_id', $character->id);
            }

            return $query
                ->orderByDesc(Schema::hasColumn($table, 'created_at') ? 'created_at' : 'id')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'occurred_at' => $row->created_at ?? null,
                    'summary' => $row->message ?? $row->error_message ?? $row->level ?? 'error',
                    'detail' => $row->context ?? $row->trace ?? $row->exception ?? '-',
                ]);
        }

        return collect();
    }

    private function enemyCandidates(): Collection
    {
        if (!Schema::hasTable('enemies')) {
            return collect();
        }

        $query = Enemy::query()->with('area.city')->orderBy('level')->orderBy('id');

        if ($this->enemySearch !== '') {
            $search = '%' . trim($this->enemySearch) . '%';
            $query->where(function ($enemyQuery) use ($search) {
                $enemyQuery->where('name', 'like', $search)
                    ->orWhereHas('area', fn ($areaQuery) => $areaQuery->where('name', 'like', $search));
            });
        }

        return $query->limit(20)->get();
    }

    private function cityProgress(?Character $character): Collection
    {
        if (!$character || !Schema::hasTable('cities')) {
            return collect();
        }

        return DB::table('cities')
            ->orderBy('id')
            ->get()
            ->map(function ($city) use ($character) {
                $cityAreas = $character->areaProgresses->filter(fn ($progress) => (int) ($progress->area?->city_id ?? 0) === (int) $city->id);

                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'is_current' => (int) $character->current_city_id === (int) $city->id,
                    'is_highest' => (int) $character->highest_city_id === (int) $city->id,
                    'unlocked_areas' => $cityAreas->where('is_unlocked', true)->count(),
                    'cleared_areas' => $cityAreas->where('boss_defeated', true)->count(),
                ];
            });
    }

    private function simulationSnapshot(Character $character, ?array $finalStats): array
    {
        return [
            'character' => [
                'id' => $character->id,
                'user_id' => $character->user_id,
                'name' => $character->name,
                'level' => $character->level,
                'job' => $character->currentJob?->name,
                'job_id' => $character->current_job_id,
                'current_hp' => $character->current_hp,
                'current_mp' => $character->current_mp,
                'money' => $character->money,
                'kiseki' => [
                    'total' => $character->kiseki,
                    'paid' => $character->paid_kiseki,
                    'free' => $character->free_kiseki,
                ],
            ],
            'base_stats' => [
                'hp' => $character->hp_base,
                'mp' => $character->mp_base,
                'atk' => $character->attack_base,
                'def' => $character->defense_base,
                'mag' => $character->magic_base,
                'spr' => $character->spirit_base,
                'spd' => $character->speed_base,
                'luk' => $character->luck_base,
            ],
            'final_stats' => $finalStats,
            'equipped_items' => $character->characterItems
                ->where('is_equipped', true)
                ->map(fn ($item) => [
                    'character_item_id' => $item->id,
                    'slot' => $item->equipped_slot,
                    'name' => $item->displayName(),
                    'type' => $item->item?->type,
                    'item_id' => $item->item_id,
                ])
                ->values()
                ->all(),
            'progress' => [
                'current_city' => $character->currentCity?->name,
                'highest_city' => $character->highestCity?->name,
                'cleared_area_ids' => $character->areaProgresses->where('boss_defeated', true)->pluck('area_id')->values()->all(),
                'unlocked_area_ids' => $character->areaProgresses->where('is_unlocked', true)->pluck('area_id')->values()->all(),
            ],
        ];
    }
}
