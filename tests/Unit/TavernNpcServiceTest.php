<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\NpcMaster;
use App\Models\PlayerTavernDailyNpc;
use App\Services\TavernNpcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TavernNpcServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('player_tavern_daily_npcs');
        Schema::dropIfExists('npc_master');

        Schema::create('npc_master', function (Blueprint $table): void {
            $table->unsignedInteger('npc_id')->primary();
            $table->string('npc_name');
            $table->string('npc_rank')->default('common');
            $table->string('npc_title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('base_weight')->default(100);
            $table->string('appear_condition_type')->default('always');
            $table->string('appear_condition_value')->default('0');
            $table->text('description')->nullable();
            $table->text('talk_text');
            $table->text('hint_text')->nullable();
            $table->timestamps();
        });

        Schema::create('player_tavern_daily_npcs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->date('tavern_date');
            $table->unsignedInteger('npc_id');
            $table->unsignedTinyInteger('slot_no');
            $table->timestamps();
            $table->unique(['character_id', 'tavern_date', 'slot_no']);
        });

        foreach ([1, 2, 3, 16] as $npcId) {
            NpcMaster::create([
                'npc_id' => $npcId,
                'npc_name' => $npcId === 16 ? '砂読みのサーラ' : "冒険者{$npcId}",
                'npc_rank' => 'common',
                'npc_title' => '一般冒険者',
                'is_active' => true,
                'sort_order' => $npcId,
                'base_weight' => 100,
                'appear_condition_type' => 'always',
                'appear_condition_value' => '0',
                'talk_text' => 'テスト会話',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('player_tavern_daily_npcs');
        Schema::dropIfExists('npc_master');

        parent::tearDown();
    }

    public function test_reunion_npc_is_not_added_when_already_in_today_tavern_slots(): void
    {
        $character = $this->character();
        $date = now()->toDateString();

        $this->dailyNpc($character, $date, 1, 16);

        app(TavernNpcService::class)->addTodayReunionNpc($character, NpcMaster::findOrFail(16));

        $this->assertFalse($this->dailyNpcExists($character, $date, 4, 16));
    }

    public function test_daily_npcs_removes_existing_duplicate_reunion_slot(): void
    {
        $character = $this->character();
        $date = now()->toDateString();

        $this->dailyNpc($character, $date, 1, 16);
        $this->dailyNpc($character, $date, 2, 1);
        $this->dailyNpc($character, $date, 3, 2);
        $this->dailyNpc($character, $date, 4, 16);

        $npcs = app(TavernNpcService::class)->dailyNpcs($character);

        $this->assertSame([16, 1, 2], $npcs->pluck('npc_id')->all());
        $this->assertFalse($this->dailyNpcExists($character, $date, 4, 16));
    }

    public function test_reunion_npc_is_added_when_not_already_in_today_tavern_slots(): void
    {
        $character = $this->character();
        $date = now()->toDateString();

        $this->dailyNpc($character, $date, 1, 1);
        $this->dailyNpc($character, $date, 2, 2);
        $this->dailyNpc($character, $date, 3, 3);

        app(TavernNpcService::class)->addTodayReunionNpc($character, NpcMaster::findOrFail(16));

        $this->assertTrue($this->dailyNpcExists($character, $date, 4, 16));
    }

    private function character(): Character
    {
        return new Character([
            'id' => 100,
            'name' => '酒場テスト',
            'level' => 1,
            'wins' => 0,
            'losses' => 0,
            'money' => 100,
            'current_city_id' => 1,
            'highest_city_id' => 1,
        ]);
    }

    private function dailyNpc(Character $character, string $date, int $slotNo, int $npcId): void
    {
        PlayerTavernDailyNpc::create([
            'character_id' => $character->id,
            'tavern_date' => $date,
            'slot_no' => $slotNo,
            'npc_id' => $npcId,
        ]);
    }

    private function dailyNpcExists(Character $character, string $date, int $slotNo, int $npcId): bool
    {
        return PlayerTavernDailyNpc::where('character_id', $character->id)
            ->whereDate('tavern_date', $date)
            ->where('slot_no', $slotNo)
            ->where('npc_id', $npcId)
            ->exists();
    }
}
