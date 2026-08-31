<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\JobClass;
use App\Models\Title;
use App\Services\TitleUnlockService;
use App\Support\MonsterMarkTitleCatalog;
use Database\Seeders\TitleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TitleUnlockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('weapon_rank')->nullable();
            $table->string('armor_rank')->nullable();
            $table->string('innate_killer_species_key')->nullable();
            $table->decimal('innate_killer_damage_rate', 8, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('character_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('enhance_level')->default(0);
            $table->unsignedBigInteger('affix_prefix_id')->nullable();
            $table->unsignedBigInteger('affix_suffix_id')->nullable();
            $table->unsignedInteger('affix_prefix_level')->default(0);
            $table->unsignedInteger('affix_suffix_level')->default(0);
            $table->string('affix_quality')->nullable();
            $table->string('killer_species_key')->nullable();
            $table->decimal('killer_damage_rate', 8, 4)->default(0);
            $table->string('resist_species_key')->nullable();
            $table->decimal('species_damage_reduction_rate', 8, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('enemies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->string('name');
            $table->boolean('is_boss')->default(false);
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('monster_marks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enemy_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('character_monster_marks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('monster_mark_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('unlocked_level')->default(0);
            $table->timestamps();
            $table->unique(['character_id', 'monster_mark_id']);
        });

        Schema::create('titles', function (Blueprint $table): void {
            $table->id();
            $table->string('category')->nullable();
            $table->string('rarity')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('hint')->nullable();
            $table->string('unlock_type');
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('source_master')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
        });

        Schema::create('character_titles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('title_id');
            $table->boolean('is_equipped')->default(false);
            $table->timestamps();
        });

        $this->characterTitleUniqueMigration()->up();

        Schema::create('character_area_progresses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('area_id');
            $table->boolean('boss_defeated')->default(false);
            $table->timestamps();
        });

        Schema::create('job_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('rank');
            $table->unsignedInteger('max_level')->default(10);
            $table->timestamps();
        });

        Schema::create('character_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('job_class_id');
            $table->unsignedInteger('job_level')->default(1);
            $table->unsignedInteger('job_exp')->default(0);
            $table->boolean('is_mastered')->default(false);
            $table->timestamp('mastered_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_check_all_unlocks_grants_reached_progress_titles_once(): void
    {
        $character = $this->createCharacter(level: 30, wins: 2000);

        $this->createTitle(112, '一人前の冒険者', 'character_level', 'level', '30');
        $this->createTitle(113, '極限に至りし者', 'character_level', 'level', '255');
        $this->createTitle(114, '千勝の戦巧者', 'battle_win_count', 'count', '1000');
        $this->createTitle(115, '常勝の冒険者', 'battle_win_count', 'count', '2000');
        $this->createTitle(116, '勝利を極めし者', 'battle_win_count', 'count', '3000');

        $service = app(TitleUnlockService::class);
        $unlocked = $service->checkAllUnlocks($character);

        $this->assertEqualsCanonicalizing(
            ['一人前の冒険者', '千勝の戦巧者', '常勝の冒険者'],
            collect($unlocked)->pluck('name')->all()
        );
        $this->assertEqualsCanonicalizing(
            [112, 114, 115],
            $character->titles()->pluck('title_id')->map(fn ($id) => (int) $id)->all()
        );

        $this->assertSame([], $service->checkAllUnlocks($character));
        $this->assertSame(3, $character->titles()->count());
    }

    public function test_progress_titles_remain_locked_before_their_thresholds(): void
    {
        $character = $this->createCharacter(level: 29, wins: 999);

        $this->createTitle(112, '一人前の冒険者', 'character_level', 'level', '30');
        $this->createTitle(114, '千勝の戦巧者', 'battle_win_count', 'count', '1000');

        $this->assertSame([], app(TitleUnlockService::class)->checkAllUnlocks($character));
        $this->assertSame(0, $character->titles()->count());
    }

    public function test_job_rank_titles_match_current_keys_and_legacy_mixed_case_targets(): void
    {
        $character = $this->createCharacter(level: 30, wins: 0);
        $advancedJob = JobClass::query()->create([
            'name' => '上級試験職',
            'rank' => 'advanced',
            'max_level' => 10,
        ]);
        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $advancedJob->id,
            'job_level' => 1,
        ]);

        $this->createTitle(97, '上級職の門を開く者', 'first_rank_job', 'rank', 'Advanced');

        $unlocked = app(TitleUnlockService::class)->checkJobTitles($character);

        $this->assertSame(['上級職の門を開く者'], collect($unlocked)->pluck('name')->all());
        $this->assertTrue($character->titles()->where('title_id', 97)->exists());
    }

    public function test_equipment_titles_grant_from_current_owned_equipment_once(): void
    {
        $character = $this->createCharacter(level: 1, wins: 0);
        $this->createEquipmentTitles();

        $weaponId = $this->createItem('神工試験剣', 'weapon', weaponRank: 'SS');
        $armorId = $this->createItem('耐性試験鎧', 'armor', armorRank: 'SS');
        $this->createCharacterItem($character, $weaponId, [
            'enhance_level' => 30,
            'affix_prefix_id' => 1,
            'affix_suffix_id' => 1,
            'affix_prefix_level' => 5,
            'affix_suffix_level' => 5,
            'affix_quality' => 'excellent',
            'killer_species_key' => 'beast',
            'killer_damage_rate' => 0.30,
        ]);
        $this->createCharacterItem($character, $armorId, [
            'affix_suffix_id' => 2,
            'affix_suffix_level' => 1,
            'affix_quality' => 'normal',
            'resist_species_key' => 'dragon',
            'species_damage_reduction_rate' => 0.05,
        ]);

        $service = app(TitleUnlockService::class);
        $unlocked = $service->checkAllUnlocks($character);

        $this->assertEqualsCanonicalizing(
            range(122, 131),
            collect($unlocked)->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame([], $service->checkAllUnlocks($character));
        $this->assertSame(10, $character->titles()->whereBetween('title_id', [122, 131])->count());
    }

    public function test_equipment_trait_titles_use_the_effective_rank_cap(): void
    {
        $character = $this->createCharacter(level: 1, wins: 0);
        $this->createTitle(129, '特性を磨く者', 'equipment_trait_level', 'trait_level', '3');
        $this->createTitle(130, '特性を極めし者', 'equipment_trait_level', 'trait_level', '5');

        $weaponId = $this->createItem('低位試験剣', 'weapon', weaponRank: 'G');
        $this->createCharacterItem($character, $weaponId, [
            'affix_prefix_id' => 1,
            'affix_prefix_level' => 5,
            'affix_quality' => 'normal',
        ]);

        $this->assertSame([], app(TitleUnlockService::class)->checkEquipmentTitles($character));
    }

    public function test_master_defined_innate_killer_unlocks_the_weapon_killer_title(): void
    {
        $character = $this->createCharacter(level: 1, wins: 0);
        $this->createTitle(127, '魔物狩りの刃', 'weapon_species_killer', 'killer', 'any');

        $weaponId = $this->createItem(
            '固有特攻試験剣',
            'weapon',
            weaponRank: 'S',
            innateKillerSpeciesKey: 'machine',
            innateKillerDamageRate: 0.12,
        );
        $this->createCharacterItem($character, $weaponId);

        $unlocked = app(TitleUnlockService::class)->checkEquipmentTitles($character);

        $this->assertSame(['魔物狩りの刃'], collect($unlocked)->pluck('name')->all());
    }

    public function test_new_achievement_title_backfill_dry_run_and_apply_are_idempotent(): void
    {
        $character = $this->createCharacter(level: 255, wins: 3000);
        $this->createNewProgressionTitles();
        $this->createEquipmentTitles();
        $this->createMonsterMarkTitles();

        foreach (['middle', 'super', 'crown', 'hero', 'myth'] as $rank) {
            $job = JobClass::query()->create([
                'name' => "一括付与{$rank}職",
                'rank' => $rank,
                'max_level' => 10,
            ]);
            CharacterJob::query()->create([
                'character_id' => $character->id,
                'job_class_id' => $job->id,
                'job_level' => 1,
            ]);
        }

        $weaponId = $this->createItem('一括付与試験剣', 'weapon', weaponRank: 'SS');
        $armorId = $this->createItem('一括付与試験鎧', 'armor', armorRank: 'SS');
        $this->createCharacterItem($character, $weaponId, [
            'enhance_level' => 30,
            'affix_prefix_id' => 1,
            'affix_suffix_id' => 1,
            'affix_prefix_level' => 5,
            'affix_suffix_level' => 5,
            'affix_quality' => 'excellent',
            'killer_species_key' => 'beast',
            'killer_damage_rate' => 0.30,
        ]);
        $this->createCharacterItem($character, $armorId, [
            'affix_suffix_id' => 2,
            'affix_suffix_level' => 1,
            'affix_quality' => 'normal',
            'resist_species_key' => 'dragon',
            'species_damage_reduction_rate' => 0.05,
        ]);

        foreach (['一括付与印A', '一括付与印B'] as $enemyName) {
            $markId = $this->createMonsterMark($this->createEnemy(1, $enemyName));
            $this->setMonsterMarkQuantity($character, $markId, 15);
        }

        $exitCode = Artisan::call('titles:backfill-new-achievements', ['--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"database_driver":"sqlite"', $output);
        $this->assertStringContainsString('"mode":"dry-run"', $output);
        $this->assertStringContainsString('"grants_missing_or_applied":22', $output);
        $this->assertSame(0, $character->titles()->count());

        $exitCode = Artisan::call('titles:backfill-new-achievements', ['--apply' => true, '--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"mode":"apply"', $output);
        $this->assertStringContainsString('"grants_missing_or_applied":22', $output);
        $this->assertSame(22, $character->titles()->whereBetween('title_id', [112, 271])->count());

        $exitCode = Artisan::call('titles:backfill-new-achievements', ['--apply' => true, '--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"grants_missing_or_applied":0', $output);
        $this->assertSame(22, $character->titles()->whereBetween('title_id', [112, 271])->count());
    }

    public function test_character_title_unique_migration_matches_the_real_base_schema(): void
    {
        $indexes = Schema::getIndexes('character_titles');
        $hasUniquePair = collect($indexes)->contains(static function (array $index): bool {
            return ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['character_id', 'title_id'];
        });

        $this->assertTrue($hasUniquePair);

        DB::table('character_titles')->insert([
            'character_id' => 999,
            'title_id' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('character_titles')->insert([
            'character_id' => 999,
            'title_id' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_character_title_unique_migration_refuses_existing_duplicates(): void
    {
        Schema::table('character_titles', function (Blueprint $table): void {
            $table->dropUnique('character_titles_character_title_unique');
        });

        DB::table('character_titles')->insert([
            [
                'character_id' => 999,
                'title_id' => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'character_id' => 999,
                'title_id' => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('プレイヤー所持データを自動削除せず移行を中止します');
        $this->characterTitleUniqueMigration()->up();
    }

    public function test_schema_audit_reports_duplicates_and_unique_constraint_without_title_masters(): void
    {
        $exitCode = Artisan::call('titles:backfill-new-achievements', [
            '--audit-schema' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"mode":"schema-audit"', $output);
        $this->assertStringContainsString('"duplicate_pairs_before":0', $output);
        $this->assertStringContainsString('"unique_index_present":true', $output);
    }

    public function test_new_title_migrations_are_forward_only_and_preserve_owned_grants(): void
    {
        $progressionMigration = require database_path('migrations/2026_08_30_120000_add_progression_titles.php');
        $equipmentMigration = require database_path('migrations/2026_08_30_130000_add_equipment_titles.php');
        $monsterMarkMigration = require database_path('migrations/2026_08_31_120000_add_monster_mark_titles.php');
        $progressionMigration->up();
        $equipmentMigration->up();
        $monsterMarkMigration->up();

        DB::table('character_titles')->insert([
            'character_id' => 999,
            'title_id' => 112,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $monsterMarkMigration->down();
        $equipmentMigration->down();
        $progressionMigration->down();
        $this->characterTitleUniqueMigration()->down();

        $this->assertSame(160, Title::query()->whereBetween('id', [112, 271])->count());
        $this->assertDatabaseHas('character_titles', [
            'character_id' => 999,
            'title_id' => 112,
        ]);
        $this->assertTrue(collect(Schema::getIndexes('character_titles'))
            ->contains(static fn (array $index): bool => ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['character_id', 'title_id']));
    }

    public function test_new_title_migration_rejects_same_id_and_name_with_changed_payload(): void
    {
        $migration = require database_path('migrations/2026_08_30_120000_add_progression_titles.php');
        $migration->up();
        DB::table('titles')->where('id', 112)->update(['target_id' => '31']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different target_id value');
        $migration->up();
    }

    public function test_new_title_migration_rejects_an_additional_id_with_the_same_natural_key(): void
    {
        $migration = require database_path('migrations/2026_08_30_120000_add_progression_titles.php');
        $migration->up();
        Title::query()->forceCreate([
            'id' => 999,
            'category' => 'level',
            'rarity' => 'common',
            'name' => '重複条件の試験称号',
            'description' => '重複条件の試験称号',
            'hint' => '重複条件の試験称号',
            'unlock_type' => 'character_level',
            'target_type' => 'level',
            'target_id' => '30',
            'source_master' => 'test',
            'display_order' => 999,
            'is_hidden' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('also uses unexpected IDs 999');
        $migration->up();
    }

    public function test_monster_mark_title_migration_rejects_a_changed_existing_payload(): void
    {
        $migration = require database_path('migrations/2026_08_31_120000_add_monster_mark_titles.php');
        $migration->up();
        DB::table('titles')->where('id', 132)->update(['target_id' => '2']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different target_id value');
        $migration->up();
    }

    public function test_title_seeder_contains_the_current_progression_catalog(): void
    {
        app(TitleSeeder::class)->run();

        $this->assertSame(271, Title::query()->count());
        $this->assertSame('名相棒', Title::query()->findOrFail(111)->name);
        $this->assertSame('advanced', Title::query()->findOrFail(97)->target_id);
        $this->assertSame('legend', Title::query()->findOrFail(98)->target_id);
        $this->assertSame(20, Title::query()->whereBetween('id', [112, 131])->count());
        $this->assertSame(10, Title::query()->whereBetween('id', [122, 131])->count());
        $this->assertSame('神工の担い手', Title::query()->findOrFail(131)->name);
        $this->assertSame(140, Title::query()->whereBetween('id', [132, 271])->count());
        $this->assertSame('はじまりの草原の印収集家', Title::query()->findOrFail(132)->name);
        $this->assertSame('終焉の祭壇の印を極めし者', Title::query()->findOrFail(271)->name);

        $duplicateConditions = Title::query()
            ->selectRaw('unlock_type, target_type, target_id, COUNT(*) AS total')
            ->groupBy('unlock_type', 'target_type', 'target_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicateConditions);
    }

    public function test_monster_mark_titles_use_area_collection_and_aggregate_duplicate_mark_rows(): void
    {
        $character = $this->createCharacter(level: 1, wins: 0);
        $this->createTitle(132, 'はじまりの草原の印収集家', 'monster_mark_area_complete', 'area', '1', 'monster_mark');
        $this->createTitle(133, 'はじまりの草原の印を極めし者', 'monster_mark_area_full_complete', 'area', '1', 'monster_mark');

        $firstEnemyId = $this->createEnemy(1, '草原スライム');
        $duplicateEnemyId = $this->createEnemy(1, '草原スライム');
        $secondEnemyId = $this->createEnemy(1, '野うさぎ');
        $bossEnemyId = $this->createEnemy(1, '草原の主', isBoss: true);
        $dungeonLordEnemyId = $this->createEnemy(1, '草原の影', role: 'ダンジョン主');
        $inactiveEnemyId = $this->createEnemy(1, '旧草原ゴーレム');

        $firstMarkId = $this->createMonsterMark($firstEnemyId);
        $duplicateMarkId = $this->createMonsterMark($duplicateEnemyId);
        $secondMarkId = $this->createMonsterMark($secondEnemyId);
        $this->createMonsterMark($bossEnemyId);
        $this->createMonsterMark($dungeonLordEnemyId);
        $this->createMonsterMark($inactiveEnemyId, isActive: false);

        $this->setMonsterMarkQuantity($character, $firstMarkId, 1);
        $this->setMonsterMarkQuantity($character, $secondMarkId, 1);

        $unlocked = app(TitleUnlockService::class)->checkAllUnlocks($character);

        $this->assertSame([132], collect($unlocked)->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $this->setMonsterMarkQuantity($character, $firstMarkId, 8);
        $this->setMonsterMarkQuantity($character, $duplicateMarkId, 7);
        $this->setMonsterMarkQuantity($character, $secondMarkId, 15);

        $unlocked = app(TitleUnlockService::class)->checkAllUnlocks($character);

        $this->assertSame([133], collect($unlocked)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([], app(TitleUnlockService::class)->checkAllUnlocks($character));
    }

    private function createCharacter(int $level, int $wins): Character
    {
        return Character::query()->create([
            'name' => 'Title Tester',
            'level' => $level,
            'wins' => $wins,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);
    }

    private function createTitle(
        int $id,
        string $name,
        string $unlockType,
        string $targetType,
        string $targetId,
        string $category = 'test',
    ): Title {
        return Title::query()->forceCreate([
            'id' => $id,
            'category' => $category,
            'rarity' => 'common',
            'name' => $name,
            'description' => $name,
            'hint' => $name,
            'unlock_type' => $unlockType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'source_master' => 'test',
            'display_order' => $id,
            'is_hidden' => true,
        ]);
    }

    private function createEquipmentTitles(): void
    {
        foreach ([
            [122, '鍛冶の心得', 'equipment_enhance_level', 'enhance_level', '10'],
            [123, '百錬の使い手', 'equipment_enhance_level', 'enhance_level', '20'],
            [124, '極鍛の到達者', 'equipment_enhance_level', 'enhance_level', '30'],
            [125, '良品を見抜く者', 'equipment_quality', 'quality', 'good'],
            [126, '逸品を携えし者', 'equipment_quality', 'quality', 'excellent'],
            [127, '魔物狩りの刃', 'weapon_species_killer', 'killer', 'any'],
            [128, '堅守を纏う者', 'armor_species_resist', 'resist', 'any'],
            [129, '特性を磨く者', 'equipment_trait_level', 'trait_level', '3'],
            [130, '特性を極めし者', 'equipment_trait_level', 'trait_level', '5'],
            [131, '神工の担い手', 'equipment_masterpiece', 'masterpiece', '30:excellent:5'],
        ] as [$id, $name, $unlockType, $targetType, $targetId]) {
            $this->createTitle($id, $name, $unlockType, $targetType, $targetId, 'equipment');
        }
    }

    private function createNewProgressionTitles(): void
    {
        foreach ([
            [112, '一人前の冒険者', 'character_level', 'level', '30'],
            [113, '極限に至りし者', 'character_level', 'level', '255'],
            [114, '千勝の戦巧者', 'battle_win_count', 'count', '1000'],
            [115, '常勝の冒険者', 'battle_win_count', 'count', '2000'],
            [116, '勝利を極めし者', 'battle_win_count', 'count', '3000'],
            [117, '中級職への一歩', 'first_rank_job', 'rank', 'middle'],
            [118, '超級の境地に立つ者', 'first_rank_job', 'rank', 'super'],
            [119, '冠位を戴く者', 'first_rank_job', 'rank', 'crown'],
            [120, '英雄の道を歩む者', 'first_rank_job', 'rank', 'hero'],
            [121, '神話に名を連ねる者', 'first_rank_job', 'rank', 'myth'],
        ] as [$id, $name, $unlockType, $targetType, $targetId]) {
            $this->createTitle($id, $name, $unlockType, $targetType, $targetId);
        }
    }

    private function createMonsterMarkTitles(): void
    {
        foreach (MonsterMarkTitleCatalog::definitions() as $id => $title) {
            $this->createTitle(
                $id,
                (string) $title['name'],
                (string) $title['unlock_type'],
                (string) $title['target_type'],
                (string) $title['target_id'],
                (string) $title['category'],
            );
        }
    }

    private function createEnemy(
        int $areaId,
        string $name,
        bool $isBoss = false,
        ?string $role = null,
    ): int {
        return (int) DB::table('enemies')->insertGetId([
            'area_id' => $areaId,
            'name' => $name,
            'is_boss' => $isBoss,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMonsterMark(int $enemyId, bool $isActive = true): int
    {
        return (int) DB::table('monster_marks')->insertGetId([
            'enemy_id' => $enemyId,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setMonsterMarkQuantity(Character $character, int $monsterMarkId, int $quantity): void
    {
        DB::table('character_monster_marks')->updateOrInsert(
            [
                'character_id' => $character->id,
                'monster_mark_id' => $monsterMarkId,
            ],
            [
                'quantity' => $quantity,
                'unlocked_level' => $quantity >= 15 ? 4 : 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function createItem(
        string $name,
        string $type,
        ?string $weaponRank = null,
        ?string $armorRank = null,
        ?string $innateKillerSpeciesKey = null,
        float $innateKillerDamageRate = 0.0,
    ): int {
        return (int) DB::table('items')->insertGetId([
            'name' => $name,
            'type' => $type,
            'weapon_rank' => $weaponRank,
            'armor_rank' => $armorRank,
            'innate_killer_species_key' => $innateKillerSpeciesKey,
            'innate_killer_damage_rate' => $innateKillerDamageRate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createCharacterItem(Character $character, int $itemId, array $attributes = []): void
    {
        DB::table('character_items')->insert([
            'character_id' => $character->id,
            'item_id' => $itemId,
            'enhance_level' => 0,
            'affix_prefix_level' => 0,
            'affix_suffix_level' => 0,
            'killer_damage_rate' => 0,
            'species_damage_reduction_rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    private function dropTables(): void
    {
        foreach ([
            'character_jobs',
            'job_classes',
            'character_area_progresses',
            'character_titles',
            'character_monster_marks',
            'monster_marks',
            'enemies',
            'character_items',
            'items',
            'titles',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function characterTitleUniqueMigration(): object
    {
        return require database_path('migrations/2026_08_30_110000_add_character_title_unique_constraint.php');
    }
}
