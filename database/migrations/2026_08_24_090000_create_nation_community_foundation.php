<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->preflightLegacyNations();
        $this->addNationProfileColumns();
        $this->normalizeLegacyNations();
        $this->createCommunityTables();
        $this->insertSettings();
    }

    public function down(): void
    {
        if (Schema::hasTable('game_settings')) {
            DB::table('game_settings')->whereIn('setting_key', array_column($this->settings(), 'setting_key'))->delete();
        }

        Schema::dropIfExists('nation_activity_logs');
        Schema::dropIfExists('nation_membership_cooldowns');
        Schema::dropIfExists('nation_join_applications');

        if (Schema::hasTable('nation_memberships')) {
            DB::table('nation_memberships')->where('role', 'ruler')->update(['role' => 'king']);
        }

        if (! Schema::hasTable('nations')) {
            return;
        }

        $disableForeignKeys = DB::getDriverName() === 'sqlite';
        if ($disableForeignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            Schema::table('nations', function (Blueprint $table): void {
                if (Schema::hasColumn('nations', 'status')) {
                    $table->dropIndex('nations_status_index');
                }
                if (Schema::hasColumn('nations', 'dissolution_effective_at')) {
                    $table->dropIndex('nations_dissolution_effective_at_index');
                }
                if (Schema::hasColumn('nations', 'dissolution_requested_by_character_id') && DB::getDriverName() !== 'sqlite') {
                    $table->dropConstrainedForeignId('dissolution_requested_by_character_id');
                }

                $columns = array_values(array_filter([
                    'nation_type',
                    'recruitment_enabled',
                    'recruitment_message',
                    'emblem_key',
                    'status',
                    'dissolution_requested_at',
                    'dissolution_effective_at',
                    'dissolution_recruitment_was_enabled',
                    DB::getDriverName() === 'sqlite' ? 'dissolution_requested_by_character_id' : null,
                    'disbanded_at',
                ], fn (string $column): bool => Schema::hasColumn('nations', $column)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        } finally {
            if ($disableForeignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    private function preflightLegacyNations(): void
    {
        if (! Schema::hasTable('nations')) {
            throw new RuntimeException('国家基盤migrationを先に適用してください。');
        }
        if (! Schema::hasTable('nation_memberships')) {
            throw new RuntimeException('国家所属tableが見つかりません。');
        }

        $this->assertTransactionalNationTables();
        $this->normalizedLegacyNations(DB::table('nations')->orderBy('id')->get(['id', 'name']));

        $invalidNationIds = $this->invalidRulerNationIds(['king', 'ruler']);
        if ($invalidNationIds !== []) {
            throw new RuntimeException('統治者が1人でない既存国家があります: '.implode(',', $invalidNationIds));
        }
    }

    private function assertTransactionalNationTables(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $database = (string) DB::connection()->getDatabaseName();
        $tables = ['nations', 'nation_memberships'];
        $engines = collect(DB::select(
            'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)',
            [$database, ...$tables],
        ))->keyBy('table_name');

        $invalidTables = collect($tables)
            ->filter(function (string $table) use ($engines): bool {
                $engine = $engines->get($table)?->engine;

                return ! is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0;
            })
            ->values()
            ->all();

        if ($invalidTables !== []) {
            throw new RuntimeException('国家機能はInnoDB tableを必要とします: '.implode(',', $invalidTables));
        }
    }

    private function addNationProfileColumns(): void
    {
        if (! Schema::hasTable('nations')) {
            throw new RuntimeException('国家基盤migrationを先に適用してください。');
        }

        if (DB::getDriverName() === 'sqlite'
            && ! Schema::hasColumn('nations', 'dissolution_requested_by_character_id')) {
            // Laravel's SQLite table rebuild can cascade-delete existing nation children
            // when this FK is added inside a surrounding test transaction. SQLite can add
            // this nullable REFERENCES column natively without rebuilding the parent table.
            DB::statement(
                'ALTER TABLE "nations" ADD COLUMN "dissolution_requested_by_character_id" '
                .'INTEGER NULL REFERENCES "characters" ("id") ON DELETE SET NULL'
            );
        }

        $columns = [
            'nation_type' => fn (Blueprint $table) => $table->string('nation_type', 32)->default('kingdom'),
            'recruitment_enabled' => fn (Blueprint $table) => $table->boolean('recruitment_enabled')->default(true),
            'recruitment_message' => fn (Blueprint $table) => $table->string('recruitment_message', 100)->nullable(),
            'emblem_key' => fn (Blueprint $table) => $table->string('emblem_key', 32)->default('green_castle'),
            'status' => fn (Blueprint $table) => $table->string('status', 24)->default('active')->index(),
            'dissolution_requested_at' => fn (Blueprint $table) => $table->dateTime('dissolution_requested_at')->nullable(),
            'dissolution_effective_at' => fn (Blueprint $table) => $table->dateTime('dissolution_effective_at')->nullable()->index(),
            'dissolution_recruitment_was_enabled' => fn (Blueprint $table) => $table->boolean('dissolution_recruitment_was_enabled')->nullable(),
            'dissolution_requested_by_character_id' => function (Blueprint $table): void {
                $column = $table->foreignId('dissolution_requested_by_character_id')->nullable();

                if (DB::getDriverName() !== 'sqlite') {
                    $column->constrained('characters')->nullOnDelete();
                }
            },
            'disbanded_at' => fn (Blueprint $table) => $table->dateTime('disbanded_at')->nullable(),
        ];

        $disableForeignKeys = DB::getDriverName() === 'sqlite';
        if ($disableForeignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            foreach ($columns as $name => $definition) {
                if (Schema::hasColumn('nations', $name)) {
                    continue;
                }

                Schema::table('nations', function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        } finally {
            if ($disableForeignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }

        $this->ensureIndex('nations', ['status'], 'nations_status_index');
        $this->ensureIndex('nations', ['dissolution_effective_at'], 'nations_dissolution_effective_at_index');
        if (DB::getDriverName() !== 'sqlite') {
            $this->ensureForeignKey(
                'nations',
                'dissolution_requested_by_character_id',
                'characters',
                'nations_dissolution_requested_by_character_id_foreign',
                'set null',
            );
        }
    }

    private function normalizeLegacyNations(): void
    {
        if (! Schema::hasTable('nation_memberships')) {
            throw new RuntimeException('国家所属tableが見つかりません。');
        }

        DB::transaction(function (): void {
            $nations = DB::table('nations')->orderBy('id')->lockForUpdate()->get(['id', 'name']);
            $normalized = $this->normalizedLegacyNations($nations);

            foreach ($normalized as $nation) {
                DB::table('nations')->where('id', $nation['id'])->update([
                    'name' => $nation['name'],
                    'nation_type' => $nation['nation_type'],
                ]);
            }

            DB::table('nation_memberships')->where('role', 'king')->update(['role' => 'ruler']);

            $invalidNationIds = $this->invalidRulerNationIds(['ruler']);

            if ($invalidNationIds !== []) {
                throw new RuntimeException('統治者が1人でない既存国家があります: '.implode(',', $invalidNationIds));
            }
        }, 3);
    }

    /** @return list<array{id:int,name:string,nation_type:string}> */
    private function normalizedLegacyNations(iterable $nations): array
    {
        $suffixes = [
            'knight_state' => '騎士国',
            'republic' => '共和国',
            'kingdom' => '王国',
            'empire' => '帝国',
            'duchy' => '公国',
        ];
        $normalized = [];
        $used = [];

        foreach ($nations as $nation) {
            $baseName = trim((string) $nation->name);
            $nationType = 'kingdom';

            foreach ($suffixes as $type => $suffix) {
                if (! str_ends_with($baseName, $suffix)) {
                    continue;
                }

                $baseName = trim(mb_substr($baseName, 0, mb_strlen($baseName) - mb_strlen($suffix)));
                $nationType = $type;
                break;
            }

            if ($baseName === '' || mb_strlen($baseName) > 40) {
                throw new RuntimeException("既存国家ID {$nation->id} の基礎国家名を安全に移行できません。");
            }

            $duplicateKey = mb_strtolower($baseName);
            if (isset($used[$duplicateKey])) {
                throw new RuntimeException("既存国家ID {$used[$duplicateKey]} と {$nation->id} の基礎国家名が重複します。先に運営判断で解消してください。");
            }

            $used[$duplicateKey] = $nation->id;
            $normalized[] = ['id' => (int) $nation->id, 'name' => $baseName, 'nation_type' => $nationType];
        }

        return $normalized;
    }

    /** @param list<string> $roles
     * @return list<int>
     */
    private function invalidRulerNationIds(array $roles): array
    {
        return DB::table('nations')
            ->orderBy('id')
            ->pluck('id')
            ->filter(fn (int $nationId): bool => DB::table('nation_memberships')
                ->where('nation_id', $nationId)
                ->whereIn('role', $roles)
                ->count() !== 1)
            ->values()
            ->all();
    }

    private function createCommunityTables(): void
    {
        if (! Schema::hasTable('nation_join_applications')) {
            Schema::create('nation_join_applications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('status', 24)->default('pending');
                $table->string('message', 100)->nullable();
                $table->dateTime('requested_at');
                $table->dateTime('reviewed_at')->nullable();
                $table->foreignId('reviewed_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->dateTime('retry_after')->nullable();
                $table->timestamps();
                $table->unique(['nation_id', 'character_id'], 'nation_join_applications_nation_character_uq');
                $table->index(['character_id', 'status'], 'nation_join_applications_character_status_idx');
                $table->index(['nation_id', 'status', 'requested_at'], 'nation_join_applications_nation_status_idx');
            });
        }

        if (! Schema::hasTable('nation_membership_cooldowns')) {
            Schema::create('nation_membership_cooldowns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
                $table->dateTime('global_join_blocked_until')->nullable()->index();
                $table->foreignId('same_nation_id')->nullable()->constrained('nations')->nullOnDelete();
                $table->dateTime('same_nation_blocked_until')->nullable()->index();
                $table->dateTime('ruler_refound_blocked_until')->nullable()->index();
                $table->string('reason', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('nation_activity_logs')) {
            Schema::create('nation_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->foreignId('target_character_id')->nullable()->constrained('characters')->nullOnDelete();
                $table->string('event_type', 48);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['nation_id', 'created_at'], 'nation_activity_logs_nation_created_idx');
                $table->index(['event_type', 'created_at'], 'nation_activity_logs_event_created_idx');
            });
        }

        $this->ensureCommunityConstraints();
    }

    private function ensureCommunityConstraints(): void
    {
        $this->ensureForeignKey('nation_join_applications', 'nation_id', 'nations', 'nation_join_applications_nation_id_foreign', 'cascade');
        $this->ensureForeignKey('nation_join_applications', 'character_id', 'characters', 'nation_join_applications_character_id_foreign', 'cascade');
        $this->ensureForeignKey('nation_join_applications', 'reviewed_by_character_id', 'characters', 'nation_join_applications_reviewed_by_character_id_foreign', 'set null');
        $this->ensureIndex('nation_join_applications', ['nation_id', 'character_id'], 'nation_join_applications_nation_character_uq', true);
        $this->ensureIndex('nation_join_applications', ['character_id', 'status'], 'nation_join_applications_character_status_idx');
        $this->ensureIndex('nation_join_applications', ['nation_id', 'status', 'requested_at'], 'nation_join_applications_nation_status_idx');

        $this->ensureForeignKey('nation_membership_cooldowns', 'character_id', 'characters', 'nation_membership_cooldowns_character_id_foreign', 'cascade');
        $this->ensureForeignKey('nation_membership_cooldowns', 'same_nation_id', 'nations', 'nation_membership_cooldowns_same_nation_id_foreign', 'set null');
        $this->ensureIndex('nation_membership_cooldowns', ['character_id'], 'nation_membership_cooldowns_character_id_unique', true);
        $this->ensureIndex('nation_membership_cooldowns', ['global_join_blocked_until'], 'nation_membership_cooldowns_global_join_blocked_until_index');
        $this->ensureIndex('nation_membership_cooldowns', ['same_nation_blocked_until'], 'nation_membership_cooldowns_same_nation_blocked_until_index');
        $this->ensureIndex('nation_membership_cooldowns', ['ruler_refound_blocked_until'], 'nation_membership_cooldowns_ruler_refound_blocked_until_index');

        $this->ensureForeignKey('nation_activity_logs', 'nation_id', 'nations', 'nation_activity_logs_nation_id_foreign', 'cascade');
        $this->ensureForeignKey('nation_activity_logs', 'actor_character_id', 'characters', 'nation_activity_logs_actor_character_id_foreign', 'set null');
        $this->ensureForeignKey('nation_activity_logs', 'target_character_id', 'characters', 'nation_activity_logs_target_character_id_foreign', 'set null');
        $this->ensureIndex('nation_activity_logs', ['nation_id', 'created_at'], 'nation_activity_logs_nation_created_idx');
        $this->ensureIndex('nation_activity_logs', ['event_type', 'created_at'], 'nation_activity_logs_event_created_idx');
    }

    private function ensureForeignKey(
        string $tableName,
        string $column,
        string $referencedTable,
        string $constraintName,
        string $onDelete,
    ): void {
        if (Schema::hasForeignKey($tableName, [$column])) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $constraintName, $onDelete): void {
            $table->foreign($column, $constraintName)
                ->references('id')
                ->on($referencedTable)
                ->onDelete($onDelete);
        });
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $tableName, array $columns, string $indexName, bool $unique = false): void
    {
        if (Schema::hasIndex($tableName, $columns, $unique ? 'unique' : null)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $unique): void {
            if ($unique) {
                $table->unique($columns, $indexName);

                return;
            }

            $table->index($columns, $indexName);
        });
    }

    private function insertSettings(): void
    {
        if (! Schema::hasTable('game_settings')) {
            return;
        }

        foreach ($this->settings() as $setting) {
            if (DB::table('game_settings')->where('setting_key', $setting['setting_key'])->exists()) {
                continue;
            }

            DB::table('game_settings')->insert([
                ...$setting,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return list<array{setting_key:string,label:string,description:string,value:string,value_type:string}> */
    private function settings(): array
    {
        return [
            ['setting_key' => 'nation.application_retry_hours', 'label' => '国家 同国再申請待機時間', 'description' => '申請却下・取消後、同じ国家へ再申請できない時間。', 'value' => '24', 'value_type' => 'integer'],
            ['setting_key' => 'nation.minimum_membership_hours', 'label' => '国家 最低在籍時間', 'description' => '加入承認後、自主脱退できない時間。', 'value' => '24', 'value_type' => 'integer'],
            ['setting_key' => 'nation.leave_join_cooldown_hours', 'label' => '国家 自主脱退後加入待機', 'description' => '自主脱退後、全国家への加入申請を止める時間。', 'value' => '72', 'value_type' => 'integer'],
            ['setting_key' => 'nation.expel_join_cooldown_hours', 'label' => '国家 追放後加入待機', 'description' => '追放後、全国家への加入申請を止める時間。', 'value' => '24', 'value_type' => 'integer'],
            ['setting_key' => 'nation.expel_same_nation_cooldown_days', 'label' => '国家 追放元再申請待機', 'description' => '追放された国家へ再申請できない日数。', 'value' => '7', 'value_type' => 'integer'],
            ['setting_key' => 'nation.dissolution_wait_hours', 'label' => '国家 解散待機時間', 'description' => '解散申請から論理解散までの取消可能時間。', 'value' => '24', 'value_type' => 'integer'],
            ['setting_key' => 'nation.ruler_refound_cooldown_days', 'label' => '国家 元統治者再建国待機', 'description' => '国家解散を実行した統治者が再び建国できない日数。', 'value' => '7', 'value_type' => 'integer'],
        ];
    }
};
