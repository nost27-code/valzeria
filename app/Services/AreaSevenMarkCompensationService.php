<?php

namespace App\Services;

use App\Models\AdminItemGrantLog;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\CharacterNotification;
use App\Models\MonsterMark;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AreaSevenMarkCompensationService
{
    public const OPERATION_ID = 'area7-boss-candidate-mark-20260802-v1';
    public const GRANT_TYPE = 'area7_mark_compensation';
    public const ROLLBACK_GRANT_TYPE = 'area7_mark_comp_rollback';
    public const NOTIFICATION_TYPE = 'area7_monster_mark_compensation';

    private const AREA_IDS = [7, 14, 21, 28, 35, 42, 49, 56, 63, 70];
    private const CANDIDATE_ENEMY_IDS = [
        7 => 41,
        14 => 83,
        21 => 125,
        28 => 167,
        35 => 209,
        42 => 251,
        49 => 293,
        56 => 335,
        63 => 377,
        70 => 419,
    ];

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $definitions = $this->masterDefinitions();
        $summary = $this->emptySummary($definitions, true);

        $this->targetQuery()
            ->select(['characters.id', 'characters.highest_city_id'])
            ->orderBy('characters.id')
            ->chunkById(100, function ($characters) use ($definitions, &$summary): void {
                foreach ($characters as $character) {
                    $alreadyProcessed = $this->hasDecisionLog((int) $character->id);
                    if ($alreadyProcessed) {
                        $summary['already_processed_count']++;
                    }

                    $plan = $this->planForCharacter($character, $definitions);
                    $this->accumulateSummary($summary, $plan, $alreadyProcessed);
                }
            }, 'characters.id', 'id');

        return $summary;
    }

    /** @return array<string, mixed> */
    public function execute(): array
    {
        return $this->withOperationLock(fn (): array => $this->executeLocked());
    }

    /** @return array<string, mixed> */
    private function executeLocked(): array
    {
        $definitions = $this->masterDefinitions();
        $backup = $this->writeBackup($definitions);
        $result = $this->emptySummary($definitions, false);
        $result['backup_path'] = $backup['path'];
        $result['backup_sha256'] = $backup['sha256'];
        $result['processed_count'] = 0;
        $result['skipped_count'] = 0;

        $this->targetQuery()
            ->select(['characters.id', 'characters.highest_city_id'])
            ->orderBy('characters.id')
            ->chunkById(100, function ($characters) use ($definitions, $backup, &$result): void {
                foreach ($characters as $target) {
                    $outcome = DB::transaction(function () use ($target, $definitions, $backup): array {
                        $character = Character::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
                        if ($this->hasDecisionLog((int) $character->id, true)) {
                            return ['skipped' => true, 'plan' => null];
                        }

                        $markIds = $this->markIdsForReachedCities($character, $definitions);
                        $quantities = CharacterMonsterMark::query()
                            ->where('character_id', $character->id)
                            ->whereIn('monster_mark_id', $markIds)
                            ->lockForUpdate()
                            ->pluck('quantity', 'monster_mark_id')
                            ->map(fn ($quantity): int => (int) $quantity)
                            ->all();
                        $plan = $this->planForCharacter($character, $definitions, $quantities);

                        foreach ($plan['details'] as &$detail) {
                            $grantQuantity = (int) $detail['grant_quantity'];
                            if ($grantQuantity <= 0) {
                                continue;
                            }

                            $mark = $definitions[(int) $detail['area_id']]['candidate_mark'];
                            $row = CharacterMonsterMark::query()
                                ->where('character_id', $character->id)
                                ->where('monster_mark_id', $mark->id)
                                ->lockForUpdate()
                                ->first();
                            $beforeQuantity = (int) ($row?->quantity ?? 0);
                            $grantQuantity = max(0, (int) $detail['target_quantity'] - $beforeQuantity);
                            $afterQuantity = $beforeQuantity + $grantQuantity;

                            if (! $row) {
                                $row = new CharacterMonsterMark([
                                    'character_id' => $character->id,
                                    'monster_mark_id' => $mark->id,
                                ]);
                            }
                            $row->quantity = $afterQuantity;
                            $row->unlocked_level = app(MonsterMarkService::class)
                                ->unlockedLevel($afterQuantity, $mark);
                            $row->save();

                            $detail['before_quantity'] = $beforeQuantity;
                            $detail['grant_quantity'] = $grantQuantity;
                            $detail['after_quantity'] = $afterQuantity;
                        }
                        unset($detail);

                        $plan['total_grant'] = array_sum(array_column($plan['details'], 'grant_quantity'));
                        $notification = $plan['total_grant'] > 0
                            ? $this->createNotification($character, $plan)
                            : null;

                        AdminItemGrantLog::create([
                            'character_id' => $character->id,
                            'admin_user_id' => null,
                            'grant_type' => self::GRANT_TYPE,
                            'target_type' => 'monster_mark_compensation',
                            'target_id' => self::OPERATION_ID,
                            'target_name' => 'エリア7・ボス候補印補填',
                            'quantity' => $plan['total_grant'],
                            'metadata' => [
                                'operation_id' => self::OPERATION_ID,
                                'formula' => 'max(0, round_half_up(sum(peer_four)/4) - current_candidate)',
                                'highest_city_id' => (int) $character->highest_city_id,
                                'details' => $plan['details'],
                                'notification_id' => $notification?->id,
                                'backup_path' => $backup['path'],
                                'backup_sha256' => $backup['sha256'],
                            ],
                        ]);

                        return ['skipped' => false, 'plan' => $plan];
                    }, 3);

                    if ($outcome['skipped']) {
                        $result['skipped_count']++;
                        continue;
                    }

                    $result['processed_count']++;
                    $this->accumulateSummary($result, $outcome['plan'], false);
                }
            }, 'characters.id', 'id');

        return $result;
    }

    /** @return array<string, mixed> */
    public function previewRollback(): array
    {
        $logs = $this->appliedLogs()->get();
        $result = [
            'preview' => true,
            'operation_id' => self::OPERATION_ID,
            'decision_log_count' => $logs->count(),
            'rollback_pending_count' => 0,
            'already_rolled_back_count' => 0,
            'total_marks_to_remove' => 0,
            'blockers' => [],
        ];

        foreach ($logs as $log) {
            if ($this->hasRollbackLog((int) $log->character_id)) {
                $result['already_rolled_back_count']++;
                continue;
            }

            $result['rollback_pending_count']++;
            foreach ((array) data_get($log->metadata, 'details', []) as $detail) {
                $grantQuantity = (int) ($detail['grant_quantity'] ?? 0);
                if ($grantQuantity <= 0) {
                    continue;
                }
                $currentQuantity = (int) (CharacterMonsterMark::query()
                    ->where('character_id', $log->character_id)
                    ->where('monster_mark_id', (int) $detail['candidate_mark_id'])
                    ->value('quantity') ?? 0);
                $result['total_marks_to_remove'] += $grantQuantity;
                if ($currentQuantity < $grantQuantity) {
                    $result['blockers'][] = [
                        'character_id' => (int) $log->character_id,
                        'monster_mark_id' => (int) $detail['candidate_mark_id'],
                        'current_quantity' => $currentQuantity,
                        'required_quantity' => $grantQuantity,
                    ];
                }
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function rollback(): array
    {
        return $this->withOperationLock(fn (): array => $this->rollbackLocked());
    }

    /** @return array<string, mixed> */
    private function rollbackLocked(): array
    {
        $preview = $this->previewRollback();
        if ($preview['blockers'] !== []) {
            throw new \LogicException('現在数が補填数を下回る印があります。ロールバックを中止しました。');
        }

        $result = $preview;
        $result['preview'] = false;
        $result['rolled_back_count'] = 0;
        $result['skipped_count'] = 0;

        $this->appliedLogs()->orderBy('id')->chunkById(100, function ($logs) use (&$result): void {
            foreach ($logs as $sourceLog) {
                $rolledBack = DB::transaction(function () use ($sourceLog): bool {
                    $log = AdminItemGrantLog::query()->whereKey($sourceLog->id)->lockForUpdate()->firstOrFail();
                    if ($this->hasRollbackLog((int) $log->character_id, true)) {
                        return false;
                    }

                    $rollbackDetails = [];
                    foreach ((array) data_get($log->metadata, 'details', []) as $detail) {
                        $grantQuantity = (int) ($detail['grant_quantity'] ?? 0);
                        if ($grantQuantity <= 0) {
                            continue;
                        }

                        $mark = MonsterMark::query()->findOrFail((int) $detail['candidate_mark_id']);
                        $row = CharacterMonsterMark::query()
                            ->where('character_id', $log->character_id)
                            ->where('monster_mark_id', $mark->id)
                            ->lockForUpdate()
                            ->first();
                        $beforeQuantity = (int) ($row?->quantity ?? 0);
                        if (! $row || $beforeQuantity < $grantQuantity) {
                            throw new \LogicException("character_id={$log->character_id} の印を安全に戻せません。");
                        }

                        $afterQuantity = $beforeQuantity - $grantQuantity;
                        if ($afterQuantity === 0) {
                            $row->delete();
                        } else {
                            $row->quantity = $afterQuantity;
                            $row->unlocked_level = app(MonsterMarkService::class)
                                ->unlockedLevel($afterQuantity, $mark);
                            $row->save();
                        }

                        $rollbackDetails[] = [
                            'monster_mark_id' => (int) $mark->id,
                            'removed_quantity' => $grantQuantity,
                            'before_quantity' => $beforeQuantity,
                            'after_quantity' => $afterQuantity,
                        ];
                    }

                    $notificationId = (int) data_get($log->metadata, 'notification_id', 0);
                    if ($notificationId > 0) {
                        CharacterNotification::query()
                            ->whereKey($notificationId)
                            ->where('character_id', $log->character_id)
                            ->where('type', self::NOTIFICATION_TYPE)
                            ->delete();
                    }

                    AdminItemGrantLog::create([
                        'character_id' => $log->character_id,
                        'admin_user_id' => null,
                        'grant_type' => self::ROLLBACK_GRANT_TYPE,
                        'target_type' => 'monster_mark_compensation',
                        'target_id' => self::OPERATION_ID,
                        'target_name' => 'エリア7・ボス候補印補填の取消',
                        'quantity' => (int) $log->quantity,
                        'metadata' => [
                            'operation_id' => self::OPERATION_ID,
                            'source_log_id' => (int) $log->id,
                            'details' => $rollbackDetails,
                        ],
                    ]);

                    return true;
                }, 3);

                $rolledBack ? $result['rolled_back_count']++ : $result['skipped_count']++;
            }
        });

        return $result;
    }

    /** @return array<string, mixed> */
    private function withOperationLock(callable $callback): array
    {
        $lock = Cache::lock('compensation:'.self::OPERATION_ID, 1800);
        if (! $lock->get()) {
            throw new \LogicException('同じ補填処理が実行中です。重複実行を中止しました。');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function masterDefinitions(): array
    {
        $areas = Area::query()
            ->whereIn('id', self::AREA_IDS)
            ->with(['enemies' => fn ($query) => $query
                ->where('is_boss', false)
                ->whereIn('id', collect(self::CANDIDATE_ENEMY_IDS)
                    ->flatMap(fn (int $candidateId): array => range($candidateId - 4, $candidateId))
                    ->all())])
            ->get()
            ->keyBy('id');
        $definitions = [];

        foreach (self::AREA_IDS as $areaId) {
            $area = $areas->get($areaId);
            $expectedCityId = intdiv($areaId, 7);
            if (! $area || (int) $area->city_id !== $expectedCityId || (int) $area->unlock_order !== 7) {
                throw new \LogicException("Area {$areaId} の街・表示順マスタが想定と一致しません。");
            }

            $candidateEnemyId = self::CANDIDATE_ENEMY_IDS[$areaId];
            $candidateEnemy = $area->enemies->firstWhere('id', $candidateEnemyId);
            if (! $candidateEnemy || ! str_contains((string) $candidateEnemy->role, 'ボス候補')) {
                throw new \LogicException("Area {$areaId} の補填対象ボス候補が想定と一致しません。");
            }
            $peerEnemies = $area->enemies
                ->reject(fn ($enemy): bool => (int) $enemy->id === (int) $candidateEnemy->id)
                ->reject(fn ($enemy): bool => str_contains((string) $enemy->role, 'ダンジョン主'));
            if ($peerEnemies->count() !== 4) {
                throw new \LogicException("Area {$areaId} の比較対象となる通常敵が4体ではありません。");
            }

            $marks = MonsterMark::query()
                ->where('is_active', true)
                ->whereIn('enemy_id', $peerEnemies->pluck('id')->push($candidateEnemy->id))
                ->get()
                ->keyBy('enemy_id');
            $candidateMark = $marks->get($candidateEnemy->id);
            $peerMarks = $peerEnemies->map(fn ($enemy) => $marks->get($enemy->id))->filter()->values();
            if (! $candidateMark || $peerMarks->count() !== 4) {
                throw new \LogicException("Area {$areaId} の有効な印マスタが5件そろっていません。");
            }

            $definitions[$areaId] = [
                'area_id' => $areaId,
                'area_name' => (string) $area->name,
                'city_id' => $expectedCityId,
                'candidate_enemy_id' => (int) $candidateEnemy->id,
                'candidate_mark' => $candidateMark,
                'peer_marks' => $peerMarks,
            ];
        }

        return $definitions;
    }

    private function targetQuery(): Builder
    {
        return $this->ordinaryCharacterQuery()->whereNotNull('characters.highest_city_id');
    }

    private function ordinaryCharacterQuery(): Builder
    {
        return Character::query()->whereHas('user', function (Builder $query): void {
            $query
                ->where(fn (Builder $roleQuery) => $roleQuery->whereNull('role')->orWhere('role', '!=', 'admin'))
                ->where(fn (Builder $emailQuery) => $emailQuery
                    ->whereNull('email')
                    ->orWhere('email', 'not like', 'tester_%@valzeria.local'));
        });
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function planForCharacter(Character $character, array $definitions, ?array $quantities = null): array
    {
        $reached = collect($definitions)
            ->filter(fn (array $definition): bool => (int) $definition['city_id'] <= (int) $character->highest_city_id);
        $markIds = $reached->flatMap(fn (array $definition) => collect($definition['peer_marks'])
            ->pluck('id')
            ->push($definition['candidate_mark']->id))
            ->unique()
            ->values();
        $quantities ??= CharacterMonsterMark::query()
            ->where('character_id', $character->id)
            ->whereIn('monster_mark_id', $markIds)
            ->pluck('quantity', 'monster_mark_id')
            ->map(fn ($quantity): int => (int) $quantity)
            ->all();

        $details = [];
        foreach ($reached as $definition) {
            $peerQuantities = collect($definition['peer_marks'])
                ->mapWithKeys(fn (MonsterMark $mark): array => [
                    (int) $mark->id => (int) ($quantities[$mark->id] ?? 0),
                ]);
            $targetQuantity = (int) round($peerQuantities->sum() / 4, 0, PHP_ROUND_HALF_UP);
            $candidateMark = $definition['candidate_mark'];
            $beforeQuantity = (int) ($quantities[$candidateMark->id] ?? 0);
            $grantQuantity = max(0, $targetQuantity - $beforeQuantity);

            $details[] = [
                'area_id' => (int) $definition['area_id'],
                'area_name' => (string) $definition['area_name'],
                'city_id' => (int) $definition['city_id'],
                'candidate_enemy_id' => (int) $definition['candidate_enemy_id'],
                'candidate_mark_id' => (int) $candidateMark->id,
                'candidate_mark_name' => (string) $candidateMark->mark_name,
                'peer_quantities' => $peerQuantities->all(),
                'target_quantity' => $targetQuantity,
                'before_quantity' => $beforeQuantity,
                'grant_quantity' => $grantQuantity,
                'after_quantity' => $beforeQuantity + $grantQuantity,
            ];
        }

        return [
            'character_id' => (int) $character->id,
            'highest_city_id' => (int) $character->highest_city_id,
            'total_grant' => array_sum(array_column($details, 'grant_quantity')),
            'details' => $details,
        ];
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function markIdsForReachedCities(Character $character, array $definitions): array
    {
        return collect($definitions)
            ->filter(fn (array $definition): bool => (int) $definition['city_id'] <= (int) $character->highest_city_id)
            ->flatMap(fn (array $definition) => collect($definition['peer_marks'])
                ->pluck('id')
                ->push($definition['candidate_mark']->id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function hasDecisionLog(int $characterId, bool $lock = false): bool
    {
        $query = AdminItemGrantLog::query()
            ->where('character_id', $characterId)
            ->where('grant_type', self::GRANT_TYPE)
            ->where('target_id', self::OPERATION_ID);

        return $lock ? $query->lockForUpdate()->exists() : $query->exists();
    }

    private function hasRollbackLog(int $characterId, bool $lock = false): bool
    {
        $query = AdminItemGrantLog::query()
            ->where('character_id', $characterId)
            ->where('grant_type', self::ROLLBACK_GRANT_TYPE)
            ->where('target_id', self::OPERATION_ID);

        return $lock ? $query->lockForUpdate()->exists() : $query->exists();
    }

    private function appliedLogs(): Builder
    {
        return AdminItemGrantLog::query()
            ->where('grant_type', self::GRANT_TYPE)
            ->where('target_id', self::OPERATION_ID);
    }

    private function createNotification(Character $character, array $plan): ?CharacterNotification
    {
        return app(CharacterNotificationService::class)->create(
            $character,
            'system',
            self::NOTIFICATION_TYPE,
            'エリア7の印ドロップ不具合に関する補填',
            "印ドロップ不具合の補填として、対象となる印を合計{$plan['total_grant']}個付与しました。",
            '印図鑑を確認する',
            route('monster-marks.index'),
            [
                'operation_id' => self::OPERATION_ID,
                'total_grant' => (int) $plan['total_grant'],
            ],
            10
        );
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function emptySummary(array $definitions, bool $preview): array
    {
        $byArea = [];
        foreach ($definitions as $definition) {
            $byArea[(int) $definition['area_id']] = [
                'area_id' => (int) $definition['area_id'],
                'area_name' => (string) $definition['area_name'],
                'city_id' => (int) $definition['city_id'],
                'candidate_mark_id' => (int) $definition['candidate_mark']->id,
                'candidate_mark_name' => (string) $definition['candidate_mark']->mark_name,
                'eligible_character_count' => 0,
                'recipient_count' => 0,
                'grant_quantity' => 0,
            ];
        }

        return [
            'preview' => $preview,
            'operation_id' => self::OPERATION_ID,
            'target_character_count' => (int) $this->targetQuery()->count(),
            'ordinary_missing_highest_city_count' => (int) $this->ordinaryCharacterQuery()
                ->whereNull('characters.highest_city_id')->count(),
            'excluded_admin_count' => (int) Character::query()
                ->whereHas('user', fn (Builder $query) => $query->where('role', 'admin'))->count(),
            'excluded_tester_count' => (int) Character::query()
                ->whereHas('user', fn (Builder $query) => $query->where('email', 'like', 'tester_%@valzeria.local'))->count(),
            'already_processed_count' => 0,
            'recipient_count' => 0,
            'total_grant_quantity' => 0,
            'by_area' => $byArea,
        ];
    }

    private function accumulateSummary(array &$summary, array $plan, bool $alreadyProcessed): void
    {
        if (! $alreadyProcessed && (int) $plan['total_grant'] > 0) {
            $summary['recipient_count']++;
            $summary['total_grant_quantity'] += (int) $plan['total_grant'];
        }

        foreach ($plan['details'] as $detail) {
            $areaId = (int) $detail['area_id'];
            $summary['by_area'][$areaId]['eligible_character_count']++;
            if (! $alreadyProcessed && (int) $detail['grant_quantity'] > 0) {
                $summary['by_area'][$areaId]['recipient_count']++;
                $summary['by_area'][$areaId]['grant_quantity'] += (int) $detail['grant_quantity'];
            }
        }
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function writeBackup(array $definitions): array
    {
        $snapshot = [
            'operation_id' => self::OPERATION_ID,
            'created_at' => now()->toIso8601String(),
            'characters' => [],
        ];
        $this->targetQuery()
            ->select(['characters.id', 'characters.highest_city_id'])
            ->orderBy('characters.id')
            ->chunkById(100, function ($characters) use ($definitions, &$snapshot): void {
                foreach ($characters as $character) {
                    $snapshot['characters'][] = $this->planForCharacter($character, $definitions);
                }
            }, 'characters.id', 'id');

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $relativePath = 'compensations/'.self::OPERATION_ID.'-before-'.now()->format('Ymd_His').'.json';
        if (! Storage::disk('local')->put($relativePath, $json)) {
            throw new \RuntimeException('補填前バックアップを書き込めませんでした。配布は開始していません。');
        }

        return [
            'path' => Storage::disk('local')->path($relativePath),
            'sha256' => hash('sha256', $json),
        ];
    }
}
