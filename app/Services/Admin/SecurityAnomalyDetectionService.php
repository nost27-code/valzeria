<?php

namespace App\Services\Admin;

use App\Models\Character;
use App\Models\SecurityAnomalyCase;
use App\Models\SecurityInventorySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SecurityAnomalyDetectionService
{
    /** @var array<int, Character|null> */
    private array $characterCache = [];

    /** @return array{created:int,updated:int,rules:array<string,int>,skipped:bool} */
    public function scan(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'rules' => [], 'skipped' => false];

        if (! $this->schemaReady()) {
            return $result;
        }

        $lock = Cache::lock('security-anomaly-detection:scan', 240);
        if (! $lock->get()) {
            $result['skipped'] = true;

            return $result;
        }

        try {
            DB::table('security_login_observations')
                ->where('last_observed_at', '<', now()->subDays((int) config('security_anomaly_detection.retention_days', 90)))
                ->delete();

            if (! config('security_anomaly_detection.enabled', true)) {
                return $result;
            }

            $this->detectRapidBattles($result);
            $this->detectCurrencyChanges('gold_transactions', 'gold_change', 'Gold', $result);
            $this->detectCurrencyChanges('kiseki_transactions', 'kiseki_change', '輝石', $result);
            $this->detectUnexpectedJobExp($result);
            $this->detectSharedIps($result);
            $this->detectInventoryGrowth($result);
            $this->detectAdminGrantTrades($result);

            return $result;
        } finally {
            $lock->release();
        }
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('security_anomaly_cases')
            && Schema::hasTable('security_anomaly_case_signatures')
            && Schema::hasTable('security_login_observations')
            && Schema::hasTable('security_inventory_snapshots');
    }

    /** @param array{created:int,updated:int,rules:array<string,int>} $result */
    private function detectRapidBattles(array &$result): void
    {
        if (! Schema::hasTable('battle_logs')) {
            return;
        }

        $rule = config('security_anomaly_detection.rules.rapid_battles');
        $since = now()->subMinutes((int) $rule['window_minutes']);
        $groups = DB::table('battle_logs')
            ->select('character_id', DB::raw('COUNT(*) as battle_count'), DB::raw('MAX(id) as max_id'))
            ->where('created_at', '>=', $since)
            ->groupBy('character_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $rule['threshold']])
            ->get();

        foreach ($groups as $group) {
            $subject = $this->subjectForCharacter((int) $group->character_id);
            if ($subject === null) {
                continue;
            }

            $this->recordDetection($result, [
                'rule_key' => 'rapid_battles', 'severity' => 'critical', ...$subject,
                'title' => '短時間の大量戦闘',
                'summary' => sprintf('%d分間に%s回の戦闘を記録しました。', $rule['window_minutes'], number_format((int) $group->battle_count)),
                'evidence' => ['window_minutes' => (int) $rule['window_minutes'], 'battle_count' => (int) $group->battle_count, 'threshold' => (int) $rule['threshold'], 'latest_battle_log_id' => (int) $group->max_id],
                'signature' => 'battle:'.$group->max_id,
            ]);
        }
    }

    /** @param array{created:int,updated:int,rules:array<string,int>} $result */
    private function detectCurrencyChanges(string $table, string $ruleKey, string $label, array &$result): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rule = config("security_anomaly_detection.rules.{$ruleKey}");
        $rows = DB::table($table)
            ->select('character_id')
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as incoming')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as outgoing')
            ->selectRaw('MAX(ABS(amount)) as single_max')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('MAX(id) as latest_id')
            ->where('created_at', '>=', now()->subMinutes((int) $rule['window_minutes']))
            ->groupBy('character_id')
            ->get();

        foreach ($rows as $row) {
            $incoming = (int) $row->incoming;
            $outgoing = (int) $row->outgoing;
            $singleMax = (int) $row->single_max;
            if (max($incoming, $outgoing) < (int) $rule['total_threshold'] && $singleMax < (int) $rule['single_threshold']) {
                continue;
            }

            $subject = $this->subjectForCharacter((int) $row->character_id);
            if ($subject === null) {
                continue;
            }

            $latestId = (int) $row->latest_id;
            $this->recordDetection($result, [
                'rule_key' => $ruleKey, 'severity' => $ruleKey === 'kiseki_change' ? 'critical' : 'warning', ...$subject,
                'title' => "異常な{$label}増減",
                'summary' => sprintf('%d分間の増加%s、減少%s、単一最大%sを検知しました。', $rule['window_minutes'], number_format($incoming), number_format($outgoing), number_format($singleMax)),
                'evidence' => ['window_minutes' => (int) $rule['window_minutes'], 'incoming' => $incoming, 'outgoing' => $outgoing, 'single_max' => $singleMax, 'transaction_count' => (int) $row->transaction_count, 'latest_transaction_id' => $latestId],
                'signature' => $table.':'.$latestId,
            ]);
        }
    }

    /** @param array{created:int,updated:int,rules:array<string,int>} $result */
    private function detectUnexpectedJobExp(array &$result): void
    {
        $rule = config('security_anomaly_detection.rules.job_exp');
        $max = (int) $rule['max_per_reward'];
        $since = now()->subHours((int) $rule['window_hours']);
        $sources = [
            ['table' => 'battle_logs', 'character' => 'character_id', 'label' => '通常探索'],
            ['table' => 'tower_run_events', 'character' => 'character_id', 'label' => '星樹の塔'],
            ['table' => 'champ_battle_logs', 'character' => 'challenger_character_id', 'label' => 'チャンプ戦'],
        ];

        foreach ($sources as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], 'job_exp_gained')) {
                continue;
            }

            $rows = DB::table($source['table'])
                ->select('id', $source['character'].' as character_id', 'job_exp_gained', 'created_at')
                ->where('created_at', '>=', $since)
                ->where('job_exp_gained', '>', $max)
                ->get();

            foreach ($rows as $row) {
                $subject = $this->subjectForCharacter((int) $row->character_id);
                if ($subject === null) {
                    continue;
                }

                $this->recordDetection($result, [
                    'rule_key' => 'unexpected_job_exp', 'severity' => 'critical', ...$subject,
                    'title' => '想定外のJob EXP',
                    'summary' => sprintf('%sの1回の報酬でJob EXP %sを記録しました。', $source['label'], number_format((int) $row->job_exp_gained)),
                    'evidence' => ['source' => $source['table'], 'source_id' => (int) $row->id, 'job_exp_gained' => (int) $row->job_exp_gained, 'allowed_max' => $max, 'occurred_at' => (string) $row->created_at],
                    'signature' => $source['table'].':'.$row->id,
                ]);
            }
        }
    }

    /** @param array{created:int,updated:int,rules:array<string,int>} $result */
    private function detectSharedIps(array &$result): void
    {
        $rule = config('security_anomaly_detection.rules.shared_ip');
        $candidates = DB::table('security_login_observations')
            ->join('users', 'users.id', '=', 'security_login_observations.user_id')
            ->select('ip_hash')
            ->selectRaw('MAX(masked_ip) as masked_ip')
            ->selectRaw('COUNT(DISTINCT security_login_observations.user_id) as account_count')
            ->selectRaw('MAX(last_observed_at) as latest_observed_at')
            ->where('last_observed_at', '>=', now()->subDays((int) $rule['window_days']))
            ->where('users.role', '!=', 'admin')
            ->when(config('security_anomaly_detection.exclude_admin_testers', true), fn ($query) => $query->where('users.email', 'not like', 'tester_%@valzeria.local'))
            ->groupBy('ip_hash')
            ->havingRaw('COUNT(DISTINCT security_login_observations.user_id) >= ?', [(int) $rule['account_threshold']])
            ->get();

        foreach ($candidates as $candidate) {
            $eligibleUsers = DB::table('security_login_observations')
                ->join('users', 'users.id', '=', 'security_login_observations.user_id')
                ->where('ip_hash', $candidate->ip_hash)
                ->where('last_observed_at', '>=', now()->subDays((int) $rule['window_days']))
                ->where('users.role', '!=', 'admin')
                ->when(config('security_anomaly_detection.exclude_admin_testers', true), fn ($query) => $query->where('users.email', 'not like', 'tester_%@valzeria.local'))
                ->distinct()->pluck('security_login_observations.user_id')->map(fn ($id) => (int) $id)->values();
            $bucketSeconds = max(86400, (int) $rule['window_days'] * 86400);
            $periodBucket = intdiv(now()->timestamp, $bucketSeconds);
            $signature = implode(',', $eligibleUsers->sort()->all()).':period:'.$periodBucket;
            $this->recordDetection($result, [
                'rule_key' => 'shared_ip', 'severity' => 'warning', 'subject_key' => (string) $candidate->ip_hash,
                'title' => '同一IPの大量アカウント',
                'summary' => sprintf('%sから%dアカウントのログインを検知しました。', $candidate->masked_ip, $eligibleUsers->count()),
                'evidence' => ['masked_ip' => $candidate->masked_ip, 'account_count' => $eligibleUsers->count(), 'user_ids' => $eligibleUsers->all(), 'window_days' => (int) $rule['window_days']],
                'signature' => $signature,
            ]);
        }
    }

    /** @param array{created:int,updated:int,rules:array<string,int>} $result */
    private function detectInventoryGrowth(array &$result): void
    {
        if (! Schema::hasTable('character_items') || ! Schema::hasTable('character_materials')) {
            return;
        }

        $rule = config('security_anomaly_detection.rules.inventory_growth');
        $equipment = DB::table('character_items')->join('items', 'items.id', '=', 'character_items.item_id')
            ->whereIn('items.type', ['weapon', 'armor', 'accessory'])
            ->select('character_items.character_id', DB::raw('COUNT(*) as total'))
            ->groupBy('character_items.character_id')->pluck('total', 'character_items.character_id');
        $materials = DB::table('character_materials')->select('character_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('character_id')->pluck('total', 'character_id');
        $characterIds = $equipment->keys()
            ->merge($materials->keys())
            ->merge(SecurityInventorySnapshot::query()->pluck('character_id'))
            ->unique();
        $this->primeCharacterCache($characterIds);
        $snapshots = SecurityInventorySnapshot::query()->whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        $snapshotRows = [];

        foreach ($characterIds as $characterId) {
            $characterId = (int) $characterId;
            $subject = $this->subjectForCharacter($characterId);
            if ($subject === null) {
                continue;
            }

            $equipmentCount = (int) ($equipment[$characterId] ?? 0);
            $materialQuantity = (int) ($materials[$characterId] ?? 0);
            $snapshot = $snapshots->get($characterId);

            if ($snapshot) {
                $equipmentDelta = $equipmentCount - (int) $snapshot->equipment_count;
                $materialDelta = $materialQuantity - (int) $snapshot->material_quantity;
                if ($equipmentDelta >= (int) $rule['equipment_threshold'] || $materialDelta >= (int) $rule['material_threshold']) {
                    $this->recordDetection($result, [
                        'rule_key' => 'inventory_growth', 'severity' => 'warning', ...$subject,
                        'title' => '装備・素材の急増',
                        'summary' => sprintf('前回走査から装備%+d個、素材%+d個の純増を検知しました。', $equipmentDelta, $materialDelta),
                        'evidence' => ['previous_equipment_count' => (int) $snapshot->equipment_count, 'equipment_count' => $equipmentCount, 'equipment_delta' => $equipmentDelta, 'previous_material_quantity' => (int) $snapshot->material_quantity, 'material_quantity' => $materialQuantity, 'material_delta' => $materialDelta, 'previous_captured_at' => $snapshot->captured_at?->toDateTimeString()],
                        'signature' => 'inventory:'.$snapshot->captured_at?->timestamp.':'.$equipmentCount.':'.$materialQuantity,
                    ]);
                }
            }

            $snapshotRows[] = [
                'character_id' => $characterId,
                'equipment_count' => $equipmentCount,
                'material_quantity' => $materialQuantity,
                'captured_at' => now(),
                'created_at' => $snapshot?->created_at ?? now(),
                'updated_at' => now(),
            ];
        }

        if ($snapshotRows !== []) {
            SecurityInventorySnapshot::query()->upsert(
                $snapshotRows,
                ['character_id'],
                ['equipment_count', 'material_quantity', 'captured_at', 'updated_at'],
            );
        }
    }

    /** @param array{created:int,updated:int,rules:array<string,int>} $result */
    private function detectAdminGrantTrades(array &$result): void
    {
        if (! Schema::hasTable('admin_item_grant_logs')) {
            return;
        }

        $rule = config('security_anomaly_detection.rules.admin_grant_trade');
        $since = now()->subHours((int) $rule['window_hours']);
        $trades = collect();

        if (Schema::hasTable('market_transactions')) {
            $materialTrades = DB::table('market_transactions')->whereNotNull('seller_character_id')
                ->when(Schema::hasColumn('market_transactions', 'seller_type'), fn ($query) => $query->where('seller_type', 'character'))
                ->where('total_price', '>=', (int) $rule['price_threshold'])->where('created_at', '>=', $since)
                ->get();
            $trades = $trades->merge($materialTrades->map(fn ($row) => ['market' => 'material', 'id' => (int) $row->id, 'seller_character_id' => (int) $row->seller_character_id, 'buyer_character_id' => (int) $row->buyer_character_id, 'target_id' => (int) $row->material_id, 'price' => (int) $row->total_price, 'occurred_at' => $row->created_at]));
        }
        if (Schema::hasTable('equipment_market_transactions')) {
            $trades = $trades->merge(DB::table('equipment_market_transactions')
                ->where('sale_price', '>=', (int) $rule['price_threshold'])->where('sold_at', '>=', $since)
                ->get()->map(fn ($row) => ['market' => 'equipment', 'id' => (int) $row->id, 'seller_character_id' => (int) $row->seller_character_id, 'buyer_character_id' => (int) $row->buyer_character_id, 'target_id' => (int) $row->character_item_id, 'price' => (int) $row->sale_price, 'occurred_at' => $row->sold_at]));
        }

        foreach ($trades as $trade) {
            $tradeAt = Carbon::parse($trade['occurred_at']);
            $grant = DB::table('admin_item_grant_logs')->where('character_id', $trade['seller_character_id'])
                ->whereBetween('created_at', [$tradeAt->copy()->subHours((int) $rule['window_hours']), $tradeAt])
                ->latest('created_at')->get()
                ->first(function ($row) use ($trade): bool {
                    if ($trade['market'] === 'material') {
                        return $row->target_type === 'material' && (int) $row->target_id === $trade['target_id'];
                    }

                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;

                    return in_array($trade['target_id'], array_map('intval', $metadata['character_item_ids'] ?? []), true);
                });
            $goldGrant = Schema::hasTable('gold_transactions')
                ? DB::table('gold_transactions')->where('character_id', $trade['buyer_character_id'])
                    ->where(fn ($query) => $query->where('type', 'admin_grant')->orWhere('source_type', 'admin_grant'))
                    ->whereBetween('created_at', [$tradeAt->copy()->subHours((int) $rule['window_hours']), $tradeAt])
                    ->latest('created_at')->first()
                : null;

            if (! $grant && ! $goldGrant) {
                continue;
            }

            $subjectCharacterId = $grant ? $trade['seller_character_id'] : $trade['buyer_character_id'];
            $subject = $this->subjectForCharacter($subjectCharacterId);
            if ($subject === null) {
                continue;
            }

            $grantType = $grant ? 'アイテム付与' : 'Gold付与';
            $grantId = $grant?->id ?? $goldGrant?->id;
            $tradeAction = $grant ? '売却' : '購入';
            $this->recordDetection($result, [
                'rule_key' => 'admin_grant_trade', 'severity' => 'critical', ...$subject,
                'title' => '管理者付与後の高額取引',
                'summary' => sprintf('%s後%d時間以内に%s市場で%s Goldの%sを検知しました。', $grantType, $rule['window_hours'], $trade['market'] === 'equipment' ? '装備' : '素材', number_format($trade['price']), $tradeAction),
                'evidence' => ['grant_type' => $grantType, 'grant_log_id' => (int) $grantId, 'market' => $trade['market'], 'trade_action' => $tradeAction, 'market_transaction_id' => $trade['id'], 'trade_price' => $trade['price'], 'trade_at' => (string) $trade['occurred_at'], 'window_hours' => (int) $rule['window_hours']],
                'signature' => $trade['market'].':'.$trade['id'].':'.$tradeAction.':grant:'.$grantId,
            ]);
        }
    }

    /** @return array{user_id:int,character_id:int}|null */
    private function subjectForCharacter(int $characterId): ?array
    {
        if (! array_key_exists($characterId, $this->characterCache)) {
            $this->characterCache[$characterId] = Character::query()->with('user')->find($characterId);
        }

        $character = $this->characterCache[$characterId];
        if (! $character || ! $character->user || $character->user->role === 'admin') {
            return null;
        }
        if (config('security_anomaly_detection.exclude_admin_testers', true) && $character->isAdminTester()) {
            return null;
        }

        return ['user_id' => (int) $character->user_id, 'character_id' => $characterId];
    }

    /** @param iterable<int|string> $characterIds */
    private function primeCharacterCache(iterable $characterIds): void
    {
        $missingIds = collect($characterIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => ! array_key_exists($id, $this->characterCache))
            ->unique()
            ->values();

        if ($missingIds->isEmpty()) {
            return;
        }

        $characters = Character::query()->with('user')->whereIn('id', $missingIds)->get()->keyBy('id');
        foreach ($missingIds as $characterId) {
            $this->characterCache[$characterId] = $characters->get($characterId);
        }
    }

    /**
     * @param  array{created:int,updated:int,rules:array<string,int>}  $result
     * @param  array<string,mixed>  $payload
     */
    private function recordDetection(array &$result, array $payload): void
    {
        $ruleKey = (string) $payload['rule_key'];
        $subject = $payload['character_id'] ?? $payload['user_id'] ?? $payload['subject_key'] ?? 'global';
        $fingerprint = hash('sha256', $ruleKey.'|'.$subject.'|'.$payload['signature']);
        if (DB::table('security_anomaly_case_signatures')->where('fingerprint', $fingerprint)->exists()) {
            return;
        }

        $existingFingerprint = SecurityAnomalyCase::query()->where('fingerprint', $fingerprint)->first();
        if ($existingFingerprint) {
            return;
        }

        $active = SecurityAnomalyCase::query()->where('rule_key', $ruleKey)
            ->whereIn('status', ['detected', 'reviewing'])
            ->when(isset($payload['character_id']), fn ($query) => $query->where('character_id', $payload['character_id']))
            ->when(! isset($payload['character_id']) && isset($payload['user_id']), fn ($query) => $query->where('user_id', $payload['user_id']))
            ->when(! isset($payload['character_id']) && ! isset($payload['user_id']) && isset($payload['subject_key']), fn ($query) => $query->where('subject_key', $payload['subject_key']))
            ->latest('last_detected_at')->first();

        if ($active) {
            $outcome = DB::transaction(function () use ($active, $fingerprint, $payload): string {
                $lockedCase = SecurityAnomalyCase::query()->lockForUpdate()->findOrFail($active->id);
                if (! in_array($lockedCase->status, ['detected', 'reviewing'], true)) {
                    return 'closed';
                }

                $inserted = DB::table('security_anomaly_case_signatures')->insertOrIgnore([
                    'security_anomaly_case_id' => $lockedCase->id,
                    'fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);
                if ($inserted === 0) {
                    return 'duplicate';
                }

                $lockedCase->update([
                    'severity' => $payload['severity'],
                    'summary' => $payload['summary'],
                    'evidence' => $payload['evidence'],
                    'detection_count' => $lockedCase->detection_count + 1,
                    'last_detected_at' => now(),
                ]);

                return 'updated';
            });
            if ($outcome === 'duplicate') {
                return;
            }
            if ($outcome === 'updated') {
                $result['updated']++;
                $result['rules'][$ruleKey] = ($result['rules'][$ruleKey] ?? 0) + 1;

                return;
            }
        }

        $case = SecurityAnomalyCase::query()->firstOrCreate(['fingerprint' => $fingerprint], [
            'rule_key' => $ruleKey,
            'severity' => $payload['severity'],
            'status' => 'detected',
            'user_id' => $payload['user_id'] ?? null,
            'character_id' => $payload['character_id'] ?? null,
            'subject_key' => $payload['subject_key'] ?? null,
            'title' => $payload['title'],
            'summary' => $payload['summary'],
            'evidence' => $payload['evidence'],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);
        if (! $case->wasRecentlyCreated) {
            return;
        }
        DB::table('security_anomaly_case_signatures')->insertOrIgnore([
            'security_anomaly_case_id' => $case->id,
            'fingerprint' => $fingerprint,
            'created_at' => now(),
        ]);
        $result['created']++;
        $result['rules'][$ruleKey] = ($result['rules'][$ruleKey] ?? 0) + 1;
    }
}
