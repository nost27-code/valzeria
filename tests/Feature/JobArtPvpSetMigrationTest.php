<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobArtPvpSetMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_08_06_120000_copy_boss_job_art_slots_to_pvp_context.php';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('character_job_art_slots');
        Schema::create('character_job_art_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->unsignedTinyInteger('slot_no');
            $table->unsignedBigInteger('skill_id');
            $table->string('activation_policy', 20)->default('normal');
            $table->timestamps();
            $table->unique(
                ['character_id', 'battle_context', 'slot_no'],
                'character_job_art_slots_context_slot_unique'
            );
            $table->unique(
                ['character_id', 'battle_context', 'skill_id'],
                'character_job_art_slots_context_skill_unique'
            );
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('character_job_art_slots');

        parent::tearDown();
    }

    public function test_up_copies_boss_slots_without_changing_normal_or_boss_rows(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->slot(1, 'normal', 1, 901, 'normal');
        $this->slot(1, 'boss', 1, 101, 'aggressive');
        $this->slot(1, 'boss', 2, 102, 'conserve');
        $this->slot(2, 'boss', 1, 201, 'normal');

        $this->migration()->up();

        $this->assertSame([901], $this->skillsFor(1, 'normal'));
        $this->assertSame([101, 102], $this->skillsFor(1, 'boss'));
        $this->assertSame([101, 102], $this->skillsFor(1, 'pvp'));
        $this->assertSame([201], $this->skillsFor(2, 'boss'));
        $this->assertSame([201], $this->skillsFor(2, 'pvp'));
        $this->assertSame(
            ['aggressive', 'conserve'],
            DB::table('character_job_art_slots')
                ->where('character_id', 1)
                ->where('battle_context', 'pvp')
                ->orderBy('slot_no')
                ->pluck('activation_policy')
                ->all()
        );
    }

    public function test_up_preserves_existing_pvp_slots_and_is_idempotent(): void
    {
        $this->slot(1, 'boss', 1, 101, 'aggressive');
        $this->slot(1, 'boss', 2, 102, 'conserve');
        $this->slot(1, 'pvp', 1, 999, 'normal');

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertSame([999, 102], $this->skillsFor(1, 'pvp'));
        $this->assertSame(2, DB::table('character_job_art_slots')->where('battle_context', 'pvp')->count());
    }

    public function test_up_skips_a_boss_skill_already_saved_in_another_pvp_slot(): void
    {
        $this->slot(1, 'boss', 1, 101, 'aggressive');
        $this->slot(1, 'pvp', 3, 101, 'conserve');

        $this->migration()->up();

        $this->assertSame([101], $this->skillsFor(1, 'pvp'));
        $this->assertSame(3, (int) DB::table('character_job_art_slots')->where('battle_context', 'pvp')->value('slot_no'));
    }

    public function test_down_does_not_delete_player_pvp_settings(): void
    {
        $this->slot(1, 'boss', 1, 101, 'aggressive');
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $this->assertSame([101], $this->skillsFor(1, 'pvp'));
    }

    public function test_migration_uses_explicit_conflict_checks_instead_of_insert_or_ignore(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertIsString($source);
        $this->assertStringContainsString("where('slot_no', \$slot->slot_no)", $source);
        $this->assertStringContainsString("where('skill_id', \$slot->skill_id)", $source);
        $this->assertStringNotContainsString('insertOrIgnore', $source);
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function slot(
        int $characterId,
        string $context,
        int $slotNo,
        int $skillId,
        string $policy
    ): void {
        DB::table('character_job_art_slots')->insert([
            'character_id' => $characterId,
            'battle_context' => $context,
            'slot_no' => $slotNo,
            'skill_id' => $skillId,
            'activation_policy' => $policy,
            'created_at' => '2026-08-06 12:00:00',
            'updated_at' => '2026-08-06 12:00:00',
        ]);
    }

    /** @return list<int> */
    private function skillsFor(int $characterId, string $context): array
    {
        return DB::table('character_job_art_slots')
            ->where('character_id', $characterId)
            ->where('battle_context', $context)
            ->orderBy('slot_no')
            ->pluck('skill_id')
            ->map(fn ($skillId): int => (int) $skillId)
            ->all();
    }
}
