<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\CharacterExplorationState;
use App\Models\JobClass;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

/** Explicit service calls only: no global override of Eloquent save/refresh. */
class ExplorationBatchStateContext
{
    private const MODELS = ['characters' => Character::class, 'character_jobs' => CharacterJob::class,
        'character_exploration_states' => CharacterExplorationState::class];
    private const FIELDS = [
        'characters' => ['level', 'exp', 'money', 'wins', 'losses', 'current_hp', 'current_mp', 'last_battle_at',
            'explore_stamina', 'explore_stamina_max', 'explore_stamina_updated_at', 'bonus_points',
            'hp_base', 'mp_base', 'attack_base', 'defense_base', 'magic_base', 'spirit_base', 'speed_base', 'luck_base',
            'hp_fraction', 'mp_fraction', 'attack_fraction', 'defense_fraction', 'magic_fraction', 'spirit_fraction', 'speed_fraction', 'luck_fraction', 'updated_at'],
        'character_jobs' => ['job_exp', 'job_level', 'is_mastered', 'mastered_at', 'updated_at'],
        'character_exploration_states' => ['exploration_point', 'chain_count', 'danger_rate', 'depth_tier',
            'last_treasure_band', 'treasure_found_count', 'secret_realm_found_count', 'dungeon_lord_encountered',
            'dungeon_lord_token', 'valmon_material_found', 'valmon_heal_used', 'rescue_insurance_enabled', 'started_at', 'updated_at'],
    ];

    private static ?WeakMap $wired = null;
    private ?Connection $connection = null;
    private ?int $characterId = null;
    private int $entryLevel = 0;
    private bool $enabled = false;
    private bool $flushing = false;
    private array $rows = [];
    private array $originals = [];
    private array $pending = [];
    private array $loaded = [];
    private array $savepoints = [];

    public static function wire(Connection $connection): void
    {
        self::$wired ??= new WeakMap();
        if (! isset(self::$wired[$connection])) {
            $connection->beforeExecuting(static fn ($sql, $bindings, $db) => app(self::class)->beforeSql($sql, $db));
            self::$wired[$connection] = true;
        }
    }

    public function withLockedCharacter(Character $character, Character $locked, callable $operation): array
    {
        if ($this->characterId !== null || (int) $locked->id !== (int) $character->id || DB::transactionLevel() === 0) {
            throw new LogicException('Batch state requires a single locked character transaction.');
        }
        $this->connection = DB::connection();
        self::wire($this->connection);
        $this->characterId = (int) $character->id;
        $this->entryLevel = $this->connection->transactionLevel();
        $this->enabled = true;
        try {
            $this->observe($locked);
            $this->refreshCharacter($character);
            $result = $operation();
            $this->flush();

            return $result;
        } finally {
            $this->rows = $this->originals = $this->pending = $this->loaded = $this->savepoints = [];
            $this->connection = null;
            $this->characterId = null;
            $this->entryLevel = 0;
            $this->enabled = $this->flushing = false;
        }
    }

    public function activeFor(Character $character): bool
    {
        return $this->enabled && $this->characterId === (int) $character->id
            && $this->connection !== null && $this->connection->transactionLevel() >= $this->entryLevel;
    }

    private function owns(Model $model): bool
    {
        return $this->enabled && $this->connection !== null && $model->exists
            && isset(self::MODELS[$model->getTable()]) && $model::class === self::MODELS[$model->getTable()]
            && (int) ($model instanceof Character ? $model->id : $model->character_id) === $this->characterId
            && $model->getConnection() === $this->connection
            && $this->connection->transactionLevel() >= $this->entryLevel;
    }

    public function save(Model $model): bool
    {
        if (! $this->owns($model)) {
            return $model->save();
        }
        $table = $model->getTable();
        $dirty = $model->getDirty();
        if (array_diff(array_keys($dirty), self::FIELDS[$table]) !== [] || $this->hasUpdateListeners($model)) {
            $this->flush();

            return $model->save();
        }
        if ($dirty === []) {
            return true;
        }
        if (! isset($this->rows[$table][$model->id])) {
            $this->observe($model->newQuery()->whereKey($model->id)->firstOrFail());
        }
        $model->updateTimestamps();
        $dirty = $model->getDirty();
        $this->rows[$table][$model->id] = array_replace($this->rows[$table][$model->id], $dirty);
        $this->pending[$table][$model->id] = array_replace($this->pending[$table][$model->id] ?? [], $dirty);
        $model->syncChanges();
        $model->setRawAttributes($this->rows[$table][$model->id], true);
        app(ExplorationBatchReadContext::class)->invalidateStateWrite($table, array_keys($dirty));
        if ($table === 'character_exploration_states') {
            app(ExplorationStateService::class)->invalidate();
        }

        return true;
    }

    public function refreshCharacter(Character $character): Character
    {
        if (! $this->activeFor($character)) {
            return $character->refresh();
        }
        if (! isset($this->rows['characters'][$character->id])) {
            $this->observe(Character::whereKey($character->id)->firstOrFail());
        }
        $character->setRawAttributes($this->rows['characters'][$character->id], true);
        $character->unsetRelations();

        return $character;
    }

    public function lockedCharacter(Character $character): Character
    {
        return $this->activeFor($character)
            ? $this->refreshCharacter(clone $character)
            : Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
    }

    public function jobsFor(Character $character): Collection
    {
        if (! $this->activeFor($character)) {
            return $character->jobHistories()->get();
        }
        if (! ($this->loaded['character_jobs'] ?? false)) {
            foreach ($character->jobHistories()->get() as $job) {
                $this->observe($job);
            }
            $this->loaded['character_jobs'] = true;
        }

        return collect($this->rows['character_jobs'] ?? [])->map(fn ($attributes) => $this->hydrate('character_jobs', $attributes));
    }

    public function currentJobFor(Character $character): ?CharacterJob
    {
        $job = $this->jobsFor($character)->firstWhere('job_class_id', $character->current_job_id);
        if ($job && $this->activeFor($character)) {
            $job->setRelation('jobClass', $this->jobClassFor((int) $job->job_class_id));
        }

        return $job;
    }

    public function jobClassFor(?int $id): ?JobClass
    {
        if (! $id) {
            return null;
        }
        if (! $this->enabled) {
            return JobClass::find($id);
        }
        $character = $this->hydrate('characters', ['id' => $this->characterId]);
        $attributes = app(ExplorationBatchReadContext::class)->remember($character, 'job-class:'.$id,
            ['job_classes'], fn () => JobClass::find($id)?->getAttributes() ?? []);

        return $attributes === [] ? null : (new JobClass())->newFromBuilder($attributes, $this->connection?->getName());
    }

    public function stateFor(Character $character): ?CharacterExplorationState
    {
        if (! $this->activeFor($character)) {
            return CharacterExplorationState::where('character_id', $character->id)->first();
        }
        if (! ($this->loaded['character_exploration_states'] ?? false)) {
            $state = CharacterExplorationState::where('character_id', $character->id)->first();
            if ($state) {
                $this->observe($state);
            }
            $this->loaded['character_exploration_states'] = true;
        }
        $rows = $this->rows['character_exploration_states'] ?? [];
        $attributes = reset($rows);

        return $attributes === false ? null : $this->hydrate('character_exploration_states', $attributes);
    }

    public function freshState(CharacterExplorationState $state): ?CharacterExplorationState
    {
        return $this->owns($state) && isset($this->rows[$state->getTable()][$state->id])
            ? $this->hydrate($state->getTable(), $this->rows[$state->getTable()][$state->id]) : $state->fresh();
    }

    public function fallback(): void
    {
        $this->flush();
        $this->enabled = false;
    }

    public function flush(?string $onlyTable = null): void
    {
        if ($this->flushing || $this->pending === []) {
            return;
        }
        if ($this->connection === null || $this->connection->transactionLevel() < $this->entryLevel) {
            throw new LogicException('Pending state cannot outlive its transaction.');
        }
        $this->flushing = true;
        try {
            foreach (self::MODELS as $table => $class) {
                if ($onlyTable !== null && $onlyTable !== $table) {
                    continue;
                }
                foreach ($this->pending[$table] ?? [] as $id => $dirty) {
                    $model = $this->hydrate($table, $this->originals[$table][$id]);
                    if ($this->hasUpdateListeners($model)) {
                        throw new LogicException('Model observers changed during deferred state preparation.');
                    }
                    $model->setRawAttributes(array_replace($model->getAttributes(), $dirty));
                    // Persist the logical save's time, not the later flush wall-clock time.
                    $model->timestamps = false;
                    if (! $model->save()) {
                        throw new LogicException('Deferred exploration state was not saved.');
                    }
                    $this->originals[$table][$id] = $model->getAttributes();
                    $this->rows[$table][$id] = $model->getAttributes();
                    unset($this->pending[$table][$id]);
                }
                unset($this->pending[$table]);
            }
        } finally {
            $this->flushing = false;
        }
    }

    public function beforeSql(string $sql, Connection $connection): void
    {
        if ($connection !== $this->connection || $this->flushing || ! $this->enabled) {
            return;
        }
        if (preg_match('/^\s*(call|exec|create|alter|drop|truncate|rename)\b/i', $sql)
            || $this->parentDeletion($sql)) {
            $this->flush();

            return;
        }
        foreach ($this->tablesIn($sql) as $table) {
            $this->flush($table);
        }
    }

    public function afterSql(string $sql, Connection $connection): void
    {
        if ($connection !== $this->connection || $this->flushing || ! $this->enabled
            || preg_match('/^\s*(select|show|explain|pragma)\b/i', $sql)) {
            return;
        }
        $tables = preg_match('/^\s*(call|exec|create|alter|drop|truncate|rename)\b/i', $sql)
            || $this->parentDeletion($sql) ? array_keys(self::MODELS) : $this->tablesIn($sql);
        foreach ($tables as $table) {
            unset($this->rows[$table], $this->originals[$table], $this->loaded[$table]);
        }
    }

    private function tablesIn(string $sql): array
    {
        $tables = [];
        foreach (self::MODELS as $table => $class) {
            $name = preg_quote($this->connection->getTablePrefix().$table, '/');
            if (preg_match('/\b(from|join|update|into|table)\s+(?:[`"]?\w+[`"]?\.)?[`"]?'.$name.'\b/i', $sql)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function parentDeletion(string $sql): bool
    {
        $prefix = preg_quote($this->connection->getTablePrefix(), '/');

        return preg_match('/^\s*(delete|replace)\b/i', $sql)
            && preg_match('/\b'.$prefix.'(users|characters)\b/i', $sql);
    }

    private function observe(Model $model): void
    {
        $this->rows[$model->getTable()][$model->id] = $this->originals[$model->getTable()][$model->id] = $model->getAttributes();
    }

    private function hydrate(string $table, array $attributes): Model
    {
        return (new (self::MODELS[$table])())->newFromBuilder($attributes, $this->connection?->getName());
    }

    private function hasUpdateListeners(Model $model): bool
    {
        foreach (['saving', 'saved', 'updating', 'updated'] as $event) {
            if ($model->getEventDispatcher()?->hasListeners('eloquent.'.$event.': '.$model::class)) {
                return true;
            }
        }

        return false;
    }

    public function transactionBeginning(Connection $connection): void
    {
        if ($connection === $this->connection) {
            $this->savepoints[$connection->transactionLevel()] = [$this->rows, $this->originals, $this->pending, $this->loaded, $this->enabled];
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
        [$this->rows, $this->originals, $this->pending, $this->loaded, $this->enabled]
            = $this->savepoints[$level + 1] ?? [[], [], [], [], false];
        foreach (array_keys($this->savepoints) as $savedLevel) {
            if ($savedLevel > $level) {
                unset($this->savepoints[$savedLevel]);
            }
        }
    }
}
