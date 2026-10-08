<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Operation-local value arrays. The caller must hold the character row lock. */
class ExplorationBatchReadContext
{
    private ?int $characterId = null;

    private ?Connection $connection = null;

    private int $transactionLevel = 0;

    private int $revision = 0;

    private array $values = [];

    private array $dependencies = [];

    private array $fingerprints = [];

    public function withLockedCharacter(Character $character, callable $operation): mixed
    {
        if ($this->characterId !== null) {
            if ($this->characterId !== (int) $character->id) {
                throw new LogicException('A batch read context cannot change character.');
            }

            return $operation();
        }
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Batch reads require the character transaction.');
        }
        $this->characterId = (int) $character->id;
        $this->connection = $connection;
        $this->transactionLevel = $connection->transactionLevel();
        try {
            return $operation();
        } finally {
            $this->invalidate();
            $this->characterId = null;
            $this->connection = null;
            $this->transactionLevel = 0;
        }
    }

    public function activeFor(Character $character): bool
    {
        return $this->characterId === (int) $character->id
            && $this->connection !== null
            && $this->connection->transactionLevel() >= $this->transactionLevel;
    }

    /** Only value arrays are retained; callers cannot mutate a shared Eloquent model. */
    public function remember(Character $character, string $key, array $tables, callable $read): array
    {
        if (! $this->activeFor($character)) {
            return $read();
        }
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        $revision = $this->revision;
        $value = $read();
        // A mutation/rollback during preparation must not repopulate an obsolete value.
        if ($revision === $this->revision && $this->activeFor($character)) {
            $this->values[$key] = $value;
            $this->dependencies[$key] = $tables;
        }

        return $value;
    }

    /** PHP value arrays retain a copy-on-write snapshot, detecting config/input edits without rehashing unchanged curves. */
    public function fingerprint(string $slot, array $inputs): string
    {
        if (isset($this->fingerprints[$slot]) && $this->fingerprints[$slot]['inputs'] === $inputs) {
            return $this->fingerprints[$slot]['hash'];
        }
        $hash = hash('sha256', serialize($inputs));
        if ($this->characterId !== null) {
            $this->fingerprints[$slot] = ['inputs' => $inputs, 'hash' => $hash];
        }

        return $hash;
    }

    public function invalidate(): void
    {
        $this->revision++;
        $this->values = $this->dependencies = $this->fingerprints = [];
    }

    public function forget(string $key): void
    {
        $this->revision++;
        unset($this->values[$key], $this->dependencies[$key]);
    }

    public function forgetFinalStats(?int $characterId = null): void
    {
        if ($characterId !== null && $characterId !== $this->characterId) {
            return;
        }
        $this->revision++;
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, 'final-stats:')) {
                unset($this->values[$key], $this->dependencies[$key]);
            }
        }
    }

    public function invalidateSql(string $sql, string $tablePrefix = ''): void
    {
        if ($this->characterId === null || preg_match('/^\s*(select|show|explain|pragma)\b/i', $sql)) {
            return;
        }
        if (preg_match('/^\s*(create|alter|drop|truncate|rename)\b/i', $sql)) {
            $this->invalidate();

            return;
        }
        // FK cascades execute without QueryExecuted events for each owned child table.
        if (preg_match('/^\s*(delete|replace)\b/i', $sql)
            && preg_match('/\b(?:'.preg_quote($tablePrefix.'characters', '/').'|'.preg_quote($tablePrefix.'users', '/').')\b/i', $sql)) {
            $this->invalidate();

            return;
        }
        if (! preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql)) {
            // Unknown mutation forms, including CTEs and leading comments, fail conservatively.
            if (preg_match('/\b(insert|update|delete|replace|alter|drop|truncate)\b/i', $sql)) {
                $this->invalidate();
            }

            return;
        }
        $this->revision++;
        foreach ($this->dependencies as $key => $tables) {
            foreach ($tables as $table) {
                if (preg_match('/\b'.preg_quote($tablePrefix.$table, '/').'\b/i', $sql)) {
                    unset($this->values[$key], $this->dependencies[$key]);
                    break;
                }
            }
        }
    }
}
