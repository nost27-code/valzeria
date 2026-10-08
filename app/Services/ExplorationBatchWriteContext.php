<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Only supplemental enemy discoveries are deferred; rewards and battle IDs stay immediate. */
class ExplorationBatchWriteContext
{
    private ?int $characterId = null;

    private ?Connection $connection = null;

    private int $entryLevel = 0;

    private array $discoveries = [];

    private array $savepoints = [];

    public function withLockedCharacter(Character $character, callable $operation): array
    {
        if ($this->characterId !== null) {
            if ($this->characterId !== (int) $character->id) {
                throw new LogicException('A batch write context cannot change character.');
            }

            return $operation();
        }
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Batch writes require the character transaction.');
        }
        $this->characterId = (int) $character->id;
        $this->connection = $connection;
        $this->entryLevel = $connection->transactionLevel();
        try {
            $result = $operation();
            $this->flush(); // Must finish before the operation UUID/result can commit.

            return $result;
        } finally {
            $this->discoveries = $this->savepoints = [];
            $this->characterId = null;
            $this->connection = null;
            $this->entryLevel = 0;
        }
    }

    public function activeFor(int $characterId): bool
    {
        return $characterId === $this->characterId && $this->connection !== null
            && $this->connection->transactionLevel() >= $this->entryLevel;
    }

    public function recordDiscovery(int $characterId, int $enemyId, string $result): bool
    {
        if (! $this->activeFor($characterId)) {
            return false;
        }
        $now = now()->toDateTimeString();
        $row = $this->discoveries[$enemyId] ?? [
            'encountered_at' => $now, 'first_win_at' => null, 'last_win_at' => null, 'wins' => 0,
        ];
        if (in_array($result, ['win', 'victory'], true)) {
            $row['first_win_at'] ??= $now;
            $row['last_win_at'] = $now;
            $row['wins']++;
        }
        $this->discoveries[$enemyId] = $row;

        return true;
    }

    public function flush(): void
    {
        if ($this->discoveries === []) {
            return;
        }
        if (! $this->activeFor((int) $this->characterId)) {
            throw new LogicException('Pending discoveries cannot outlive their transaction.');
        }
        $inserts = [];
        foreach ($this->discoveries as $enemyId => $row) {
            $inserts[] = ['character_id' => $this->characterId, 'enemy_id' => $enemyId,
                'first_encountered_at' => $row['encountered_at'], 'first_defeated_at' => null,
                'last_defeated_at' => null, 'defeat_count' => 0,
                'created_at' => $row['encountered_at'], 'updated_at' => $row['encountered_at']];
        }
        $this->connection->table(EnemyDiscoveryService::TABLE)->insertOrIgnore($inserts);
        foreach ($this->discoveries as $enemyId => $row) {
            if ($row['wins'] === 0) {
                continue;
            }
            $query = $this->connection->table(EnemyDiscoveryService::TABLE)
                ->where('character_id', $this->characterId)->where('enemy_id', $enemyId);
            (clone $query)->whereNull('first_defeated_at')->update([
                'first_defeated_at' => $row['first_win_at'], 'updated_at' => $row['first_win_at'],
            ]);
            // Atomic addition preserves existing counts, including writers outside this operation.
            $query->update(['last_defeated_at' => $row['last_win_at'],
                'defeat_count' => DB::raw('defeat_count + '.(int) $row['wins']),
                'updated_at' => $row['last_win_at']]);
        }
        $this->discoveries = [];
    }

    public function transactionBeginning(Connection $connection): void
    {
        if ($connection === $this->connection) {
            $this->savepoints[$connection->transactionLevel()] = $this->discoveries;
        }
    }

    public function transactionCommitted(Connection $connection): void
    {
        if ($connection === $this->connection) {
            unset($this->savepoints[$connection->transactionLevel() + 1]);
        }
    }

    public function transactionRolledBack(Connection $connection): void
    {
        if ($connection !== $this->connection) {
            return;
        }
        $level = $connection->transactionLevel();
        // Restore the earliest rolled-back savepoint, retaining prior completed battles.
        $this->discoveries = $level < $this->entryLevel ? [] : ($this->savepoints[$level + 1] ?? []);
        foreach (array_keys($this->savepoints) as $savedLevel) {
            if ($savedLevel > $level) {
                unset($this->savepoints[$savedLevel]);
            }
        }
    }
}
