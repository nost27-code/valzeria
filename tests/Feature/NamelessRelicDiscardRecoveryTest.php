<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\GameSetting;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NamelessRelicDiscardRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\NamelessRuinRewardReferences;
    use \Tests\Support\SharedStorageFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRuinRewardReference();
        config(['gold.battle.normal_drop_rate' => 0, 'gold.battle.boss_drop_rate' => 0]);
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'nameless_relics.drop_chance_bps' => 0, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.cleared_boss_encounter_bps' => 0, 'nameless_relics.relic_goblin_encounter_bps' => 0,
            'nameless_relics.enemy_base' => ['max_hp' => 1, 'str' => 1, 'def' => 1, 'mag' => 1, 'spr' => 1, 'agi' => 1, 'luk' => 1]]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
    }

    public function test_full_shared_material_storage_and_maxed_body_can_be_cleared_then_explored_in_production(): void
    {
        $this->app->instance('env', 'production');
        $character = $this->character();
        $rows = [];
        for ($i = 0; $i < 299; $i++) {
            $rows[] = ['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1, 'growth_progress' => 1,
                'created_at' => now(), 'updated_at' => now()];
        }
        PlayerRelic::query()->insert($rows);
        $best = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_def', 'rank' => 9]);
        $spare = PlayerRelic::query()->where('character_id', $character->id)->where('growth_progress', 1)->orderBy('id')->skip(1)->firstOrFail();
        $this->reserveMaterialSlots($character, 0);
        $workshop = app(NamelessWorkshopService::class);
        $this->assertNotNull($workshop->inventoryBlockReason($character));
        $this->reject(fn () => app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid()), '所持枠がいっぱい');
        $this->login($character);
        $data = ['relic_id' => $spare->id, 'expected_rank' => 1, 'expected_growth_progress' => 1,
            'confirmed' => 1, 'request_uuid' => (string) Str::uuid(), 'workshop_tab' => 'sets', '_token' => 'recovery-token'];
        $this->withSession(['_token' => 'recovery-token'])->post(route('nameless-workshop.act', ['action' => 'discard']), $data)
            ->assertRedirect()->assertSessionMissing('error');
        $this->assertDatabaseMissing('player_relics', ['id' => $spare->id]);
        $this->assertSame(299, PlayerRelic::query()->where('character_id', $character->id)->count());
        $this->assertNotNull($best->fresh());
        $this->assertNull($workshop->inventoryBlockReason($character));
        $this->assertSame(100000, $character->fresh()->money);
        $this->assertSame(99, $character->namelessEquipments()->firstOrFail()->forge_level);
        $result = app(NamelessRuinService::class)->fight($character->fresh(), 'sand', 1, false, (string) Str::uuid());
        $this->assertSame('victory', $result['battle_result']);
        $this->assertSame(99, $character->fresh()->explore_stamina);
        $this->assertSame(299, PlayerRelic::query()->where('character_id', $character->id)->count());
    }

    public function test_progress_discard_requires_snapshot_and_rejects_changed_rank_or_progress(): void
    {
        $character = $this->character();
        $partial = $this->partial($character);
        $workshop = app(NamelessWorkshopService::class);
        $this->reject(fn () => $workshop->discard($character, $partial->id, (string) Str::uuid()), '進捗を失う');
        $this->reject(fn () => $workshop->discard($character, $partial->id, (string) Str::uuid(), ['rank' => 1, 'growth_progress' => 0]), '変わりました');
        $old = ['rank' => 1, 'growth_progress' => 1];
        $partial->update(['rank' => 2]);
        $this->reject(fn () => $workshop->discard($character, $partial->id, (string) Str::uuid(), $old), '変わりました');
        $this->assertSame(1, $partial->fresh()->growth_progress);
        $this->assertSame(0, NamelessWorkshopOperation::query()->count());
    }

    public function test_protected_attached_best_and_foreign_relics_still_cannot_be_discarded(): void
    {
        $character = $this->character();
        $partial = $this->partial($character);
        $state = ['rank' => 1, 'growth_progress' => 1];
        $workshop = app(NamelessWorkshopService::class);
        $partial->update(['is_locked' => true]);
        $this->reject(fn () => $workshop->discard($character, $partial->id, (string) Str::uuid(), $state), '保護中');
        $partial->update(['is_locked' => false, 'nameless_equipment_id' => $character->namelessEquipments()->firstOrFail()->id, 'slot_number' => 1]);
        $this->reject(fn () => $workshop->discard($character, $partial->id, (string) Str::uuid(), $state), '装着中');
        $partial->update(['nameless_equipment_id' => null, 'slot_number' => null]);
        $this->reject(fn () => $workshop->discard($this->character(), $partial->id, (string) Str::uuid(), $state), '未所持');
        $best = PlayerRelic::query()->where('character_id', $character->id)->where('rank', 9)->firstOrFail();
        $this->reject(fn () => $workshop->discard($character, $best->id, (string) Str::uuid(), ['rank' => 9, 'growth_progress' => 0]), '最高ランク');
        $this->assertNotNull($partial->fresh());
        $this->assertNotNull($best->fresh());
    }

    public function test_replay_keeps_lost_progress_audit_and_never_consumes_another_relic(): void
    {
        $character = $this->character();
        $partial = $this->partial($character);
        $uuid = (string) Str::uuid();
        $state = ['rank' => 1, 'growth_progress' => 1];
        $workshop = app(NamelessWorkshopService::class);
        $result = $workshop->discard($character, $partial->id, $uuid, $state);
        $this->assertSame($result, $workshop->discard($character, $partial->id, $uuid, $state));
        $this->assertSame(1, $result['discarded']['growth_progress']);
        $this->assertSame(1, PlayerRelic::query()->where('character_id', $character->id)->count());
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
        $this->reject(fn () => $workshop->discard($character, $partial->id, $uuid, ['rank' => 1, 'growth_progress' => 0]), '操作番号');
    }

    public function test_failed_audit_insert_rolls_back_relic_deletion(): void
    {
        $character = $this->character();
        $partial = $this->partial($character);
        $fail = true;
        NamelessWorkshopOperation::creating(function () use (&$fail): void { if ($fail) { throw new RuntimeException('台帳保存失敗'); } });
        try {
            $this->reject(fn () => app(NamelessWorkshopService::class)->discard($character, $partial->id, (string) Str::uuid(),
                ['rank' => 1, 'growth_progress' => 1]), '台帳保存失敗');
        } finally { $fail = false; }
        $this->assertNotNull($partial->fresh());
        $this->assertSame(1, $partial->fresh()->growth_progress);
        $this->assertSame(0, NamelessWorkshopOperation::query()->count());
    }

    public function test_http_warning_confirmation_validation_and_preserved_growth_state(): void
    {
        $character = $this->character();
        $partial = $this->partial($character, 7, 3);
        $this->login($character);
        $response = $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'effect' => 'stat_str']));
        $this->assertSame(200, $response->status());
        $html = $response->getContent();
        $this->assertStringContainsString('育成進捗 3／4 は失われます', $html);
        $this->assertStringContainsString('育成に使った遺物は戻りません', $html);
        $this->assertStringContainsString('name="expected_growth_progress" value="3"', $html);
        $this->assertStringContainsString('破棄しています…', $html);
        $data = ['relic_id' => $partial->id, 'expected_rank' => 7, 'expected_growth_progress' => 3, 'request_uuid' => (string) Str::uuid()];
        $this->post(route('nameless-workshop.act', ['action' => 'discard']), $data)->assertSessionHasErrors('confirmed');
        unset($data['expected_rank']);
        $data['confirmed'] = 1;
        $this->post(route('nameless-workshop.act', ['action' => 'discard']), $data)->assertSessionHasErrors('expected_rank');
        $this->assertSame(3, $partial->fresh()->growth_progress);
        $this->assertSame(0, NamelessWorkshopOperation::query()->count());
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '満杯救済検証',
            'current_city_id' => $town->id, 'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 10000, 'current_mp' => 500,
            'attack_base' => 1000, 'defense_base' => 1000, 'magic_base' => 1000, 'spirit_base' => 1000,
            'speed_base' => 1000, 'luck_base' => 10, 'money' => 100000, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now()]);
        PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣',
            'acquisition_source' => 'starter', 'forge_level' => 99, 'base_power' => 5, 'power_per_level' => 5]);
        return $character;
    }

    private function partial(Character $character, int $rank = 1, int $progress = 1): PlayerRelic
    {
        PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 9]);
        return PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => $rank, 'growth_progress' => $progress]);
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
    }

    private function reject(callable $action, string $message): void
    {
        try { $action(); $this->fail('拒否されるべき破棄操作が成功しました。'); }
        catch (RuntimeException $exception) { $this->assertStringContainsString($message, $exception->getMessage()); }
    }
}
