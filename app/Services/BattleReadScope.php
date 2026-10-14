<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Synchronous battle operation only. Derived owned values are valid only until a relevant write. */
final class BattleReadScope
{
    private bool $active = false;

    private array $values = [];

    private ?BattleSchemaMetadata $metadata = null;

    private const DEPENDENCIES = [
        'mark_bonuses' => ['character_monster_marks', 'monster_marks', 'enemies'],
        'nameless_body' => ['player_nameless_equipments', 'game_settings'],
        'development' => ['character_area_progresses'],
    ];

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            return $operation();
        }
        $schema = Schema::getFacadeRoot();
        $previousInstance = app()->isShared('db.schema') ? $schema : null;
        $this->active = true;
        Schema::swap($this->metadata = new BattleSchemaMetadata($schema));
        try {
            return $operation();
        } finally {
            $this->invalidate();
            $this->active = false;
            $this->metadata = null;
            // Schema is normally a non-cached factory: do not pin a builder/connection after this scope.
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            if ($previousInstance !== null) {
                Schema::swap($previousInstance);
            }
        }
    }

    public function remember(string $group, string $key, callable $read): mixed
    {
        // Never retain owned values from a pre-lock read, or across independent operations.
        if (! $this->active || ! isset(self::DEPENDENCIES[$group]) || DB::transactionLevel() === 0) {
            return $read();
        }

        return $this->values[$group][$key] ??= $read();
    }

    public function wrote(string $sql): void
    {
        if (! $this->active) {
            return;
        }
        foreach (self::DEPENDENCIES as $group => $tables) {
            foreach ($tables as $table) {
                if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql)) {
                    unset($this->values[$group]);
                    break;
                }
            }
        }
    }

    public function invalidate(): void
    {
        $this->values = [];
        $this->metadata?->invalidate();
    }
}
