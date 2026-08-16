<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Skill;
use App\Services\JobArtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobArtPvpSetSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('character_job_art_slots');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('job_classes');

        Schema::create('job_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('name');
            $table->string('skill_type')->default('job_art');
            $table->string('limit_group')->nullable();
            $table->boolean('pve_enabled')->default(true);
            $table->boolean('boss_enabled')->default(true);
            $table->boolean('champ_enabled')->default(true);
        });
        Schema::create('character_job_art_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->unsignedTinyInteger('slot_no');
            $table->unsignedBigInteger('skill_id');
            $table->string('activation_policy', 20)->default('normal');
            $table->timestamps();
        });

        DB::table('job_classes')->insert(['id' => 1, 'name' => '試験職']);
        DB::table('skills')->insert([
            ['id' => 100, 'job_id' => 1, 'name' => '通常奥義', 'skill_type' => 'job_art', 'limit_group' => null, 'champ_enabled' => true],
            ['id' => 101, 'job_id' => 1, 'name' => 'ボス奥義', 'skill_type' => 'job_art', 'limit_group' => null, 'champ_enabled' => true],
            ['id' => 102, 'job_id' => 1, 'name' => '対人奥義', 'skill_type' => 'job_art', 'limit_group' => null, 'champ_enabled' => true],
            [
                'id' => 103,
                'job_id' => 1,
                'name' => '保存済み報酬戦技',
                'skill_type' => 'job_art',
                'limit_group' => 'REWARD',
                'champ_enabled' => false,
            ],
        ]);
        DB::table('character_job_art_slots')->insert([
            $this->slot('normal', 100),
            $this->slot('boss', 101),
            $this->slot('pvp', 102),
            $this->slot('pvp', 103, 2),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('character_job_art_slots');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('job_classes');

        parent::tearDown();
    }

    public function test_flag_off_uses_boss_and_flag_on_uses_pvp_without_changing_other_sets(): void
    {
        $service = new class extends JobArtService
        {
            public function availableArts(Character $character, string $context = 'pve'): Collection
            {
                return Skill::query()
                    ->with('jobClass')
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (Skill $skill): bool => $this->contextAllows($skill, $context))
                    ->each(function (Skill $skill): void {
                        $skill->setAttribute('job_art_origin', 'current');
                        $skill->setAttribute('job_art_rate', 1.0);
                    })
                    ->values();
            }
        };
        $character = new Character(['id' => 1]);
        $character->exists = true;

        config(['battle.job_art_v2.pvp_set' => false]);
        $this->assertSame([101], $service->battleArtsFor($character, 'champ')->pluck('id')->all());

        config(['battle.job_art_v2.pvp_set' => true]);
        $this->assertSame([102, 103], $service->battleArtsFor($character, 'champ')->pluck('id')->all());
        $this->assertSame([100], $service->battleArtsFor($character, 'pve')->pluck('id')->all());
        $this->assertSame([101], $service->battleArtsFor($character, 'boss')->pluck('id')->all());
    }

    private function slot(string $context, int $skillId, int $slotNo = 1): array
    {
        return [
            'character_id' => 1,
            'battle_context' => $context,
            'slot_no' => $slotNo,
            'skill_id' => $skillId,
            'activation_policy' => 'normal',
            'created_at' => '2026-08-06 12:00:00',
            'updated_at' => '2026-08-06 12:00:00',
        ];
    }
}
