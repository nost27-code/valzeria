<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\NamelessRelicGrowthService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NamelessRelicGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
    }

    public function test_all_rank_boundaries_use_three_or_five_copies_and_keep_the_target(): void
    {
        $character = $this->character();
        $before = $character->fresh()->getAttributes();
        for ($rank = 1; $rank <= 8; $rank++) {
            $target = $this->relic($character, $rank);
            $sources = collect(range(1, $rank <= 6 ? 2 : 4))->map(fn () => $this->relic($character, $rank));
            $preview = $this->growth()->preview($character, $target->id, $sources->pluck('id')->all());
            $this->assertSame($rank <= 6 ? 2 : 4, $preview['required']);
            $this->assertSame($rank + 1, $preview['rank_after']);
            $result = $this->growth()->grow($character, $target->id, $sources->pluck('id')->all(), $preview['confirmation_hash'], $this->uuid());
            $this->assertTrue($result['rank_up']);
            $this->assertSame($rank + 1, $target->fresh()->rank);
            $this->assertSame(0, $target->fresh()->growth_progress);
            $this->assertSame('stat_str', $target->fresh()->effect_key);
            foreach ($sources as $source) {
                $this->assertNull($source->fresh());
            }
        }
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertDatabaseCount('nameless_workshop_operations', 8);
    }

    public function test_partial_progress_survives_reload_and_replay_does_not_consume_again(): void
    {
        $character = $this->character();
        $target = $this->relic($character);
        $source = $this->relic($character);
        $preview = $this->growth()->preview($character, $target->id, [$source->id]);
        $this->assertSame(0, $target->fresh()->growth_progress);
        $this->assertNotNull($source->fresh());
        $uuid = $this->uuid();
        $result = $this->growth()->grow($character, $target->id, [$source->id], $preview['confirmation_hash'], $uuid);
        $this->assertSame(1, $target->fresh()->growth_progress);
        $this->assertSame(1, $target->fresh()->rank);
        $this->assertSame($result, $this->growth()->grow($character, $target->id, [$source->id], $preview['confirmation_hash'], $uuid));
        $next = $this->relic($character);
        $nextPreview = $this->growth()->preview($character, $target->id, [$next->id]);
        $this->growth()->grow($character, $target->id, [$next->id], $nextPreview['confirmation_hash'], $this->uuid());
        $this->assertSame(2, $target->fresh()->rank);
        $this->assertSame(0, $target->fresh()->growth_progress);
        $this->assertSame($result, $this->growth()->grow($character, $target->id, [$source->id], $preview['confirmation_hash'], $uuid));
        $this->reject(fn () => $this->growth()->grow($character, $target->id, [$next->id], $preview['confirmation_hash'], $uuid), '同じ操作番号');
        $this->assertDatabaseCount('nameless_workshop_operations', 2);
        $this->assertSame($source->id, $result['sources'][0]['id']);
    }

    public function test_rank_eight_accepts_four_separate_inputs_then_stops_at_nine(): void
    {
        $character = $this->character();
        $target = $this->relic($character, 8);
        for ($input = 1; $input <= 4; $input++) {
            $source = $this->relic($character, 8);
            $preview = $this->growth()->preview($character, $target->id, [$source->id]);
            $this->growth()->grow($character, $target->id, [$source->id], $preview['confirmation_hash'], $this->uuid());
            $this->assertSame($input < 4 ? 8 : 9, $target->fresh()->rank);
            $this->assertSame($input < 4 ? $input : 0, $target->fresh()->growth_progress);
        }
        $source = $this->relic($character, 9);
        $this->reject(fn () => $this->growth()->preview($character, $target->id, [$source->id]), '最大育成');
        $this->assertNotNull($source->fresh());
        $this->assertDatabaseCount('nameless_workshop_operations', 4);
    }

    public function test_invalid_materials_are_rejected_without_consuming_any_owned_relic(): void
    {
        $character = $this->character();
        $target = $this->relic($character);
        $good = $this->relic($character);
        $wrongRank = $this->relic($character, 2);
        $wrongEffect = $this->relic($character, 1, 'stat_hp');
        $protected = $this->relic($character); $protected->update(['is_locked' => true]);
        $attached = $this->relic($character); $attached->update(['nameless_equipment_id' => $this->body($character)->id, 'slot_number' => 1]);
        $partial = $this->relic($character); $partial->update(['growth_progress' => 1]);
        $foreign = $this->relic($this->character());
        $before = PlayerRelic::query()->orderBy('id')->get()->toArray();
        foreach ([$wrongRank, $wrongEffect, $protected, $attached, $partial, $foreign] as $bad) {
            $this->reject(fn () => $this->growth()->preview($character, $target->id, [$good->id, $bad->id]), '遺物');
        }
        foreach ([[], [$target->id], [$good->id, $good->id], [0], [$good->id, $wrongRank->id, $wrongEffect->id]] as $ids) {
            $this->reject(fn () => $this->growth()->preview($character, $target->id, $ids), '素材');
        }
        $this->reject(fn () => $this->growth()->preview($character, $foreign->id, [$good->id]), '所持');
        $this->assertSame($before, PlayerRelic::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_a_changed_target_invalidates_an_old_confirmation(): void
    {
        $character = $this->character();
        $target = $this->relic($character);
        $oldSource = $this->relic($character);
        $newSource = $this->relic($character);
        $old = $this->growth()->preview($character, $target->id, [$oldSource->id]);
        $new = $this->growth()->preview($character, $target->id, [$newSource->id]);
        $this->growth()->grow($character, $target->id, [$newSource->id], $new['confirmation_hash'], $this->uuid());
        $this->reject(fn () => $this->growth()->grow($character, $target->id, [$oldSource->id], $old['confirmation_hash'], $this->uuid()), '確認し直して');
        $this->assertNotNull($oldSource->fresh());
        $this->assertSame(1, $target->fresh()->growth_progress);
        $this->assertDatabaseCount('nameless_workshop_operations', 1);
    }

    public function test_a_newly_protected_or_missing_source_is_not_consumed_by_confirmation(): void
    {
        $character = $this->character();
        $target = $this->relic($character);
        $a = $this->relic($character); $b = $this->relic($character);
        $preview = $this->growth()->preview($character, $target->id, [$a->id, $b->id]);
        $b->update(['is_locked' => true]);
        $this->reject(fn () => $this->growth()->grow($character, $target->id, [$a->id, $b->id], $preview['confirmation_hash'], $this->uuid()), '保護中');
        $this->assertNotNull($a->fresh());
        $b->delete();
        $this->reject(fn () => $this->growth()->grow($character, $target->id, [$a->id, $b->id], $preview['confirmation_hash'], $this->uuid()), '所持');
        $this->assertSame(1, $target->fresh()->rank);
        $this->assertNotNull($a->fresh());
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_equipped_protected_target_keeps_attachment_and_changes_stats_without_healing(): void
    {
        $character = $this->character();
        $character->update(['current_hp' => 5000, 'current_mp' => 300]);
        $body = $this->body($character);
        $target = $this->relic($character, 1, 'stat_hp');
        $workshop = app(NamelessWorkshopService::class);
        $workshop->attach($character, $body->id, 1, $target->id, $this->uuid());
        $workshop->protect($character, $target->id, true, $this->uuid());
        $workshop->changeEquipment($character, $body->id, true, $this->uuid());
        $before = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $revision = $body->fresh()->revision;
        $sources = [$this->relic($character, 1, 'stat_hp')->id, $this->relic($character, 1, 'stat_hp')->id];
        $preview = $this->growth()->preview($character, $target->id, $sources);
        $this->growth()->grow($character, $target->id, $sources, $preview['confirmation_hash'], $this->uuid());
        $after = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertGreaterThan($before['max_hp'], $after['max_hp']);
        $this->assertSame(5000, $character->fresh()->current_hp);
        $this->assertSame(300, $character->fresh()->current_mp);
        $this->assertSame($body->id, $target->fresh()->nameless_equipment_id);
        $this->assertSame(1, $target->fresh()->slot_number);
        $this->assertTrue($target->fresh()->is_locked);
        $this->assertSame($revision + 1, $body->fresh()->revision);
    }

    public function test_failure_after_material_deletion_rolls_back_target_equipment_and_audit(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $target = $this->relic($character);
        $target->update(['nameless_equipment_id' => $body->id, 'slot_number' => 1]);
        $sources = [$this->relic($character)->id, $this->relic($character)->id];
        $preview = $this->growth()->preview($character, $target->id, $sources);
        $this->mock(CharacterStatusService::class, fn (MockInterface $mock) => $mock->shouldReceive('getFinalStats')->once()->andThrow(new RuntimeException('検証用の更新失敗')));
        $this->reject(fn () => $this->growth()->grow($character, $target->id, $sources, $preview['confirmation_hash'], $this->uuid()), '更新失敗');
        $this->assertSame(2, PlayerRelic::query()->whereIn('id', $sources)->count());
        $this->assertSame(1, $target->fresh()->rank);
        $this->assertSame(0, $target->fresh()->growth_progress);
        $this->assertSame(0, $body->fresh()->revision);
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_partially_grown_relic_is_excluded_from_materials_and_requires_discard_confirmation(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $partial = $this->relic($character); $partial->update(['growth_progress' => 1]);
        $other = $this->relic($character, 2);
        $workshop = app(NamelessWorkshopService::class);
        $this->reject(fn () => $workshop->previewFeed($character, $body->id, [], [$partial->id], 0, false), '育成途中');
        $this->reject(fn () => $workshop->discard($character, $partial->id, $this->uuid()), '育成途中');
        $this->login($character);
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'grow_relic' => $partial->id]))->assertOk()
            ->assertSee('育成進捗')->assertViewHas('forgeRelics', fn ($rows) => ! $rows->contains('id', $partial->id))
            ->assertViewHas('selectedRelicGrowth', fn ($row) => $row->id === $partial->id);
        $this->assertSame(1, $partial->fresh()->growth_progress);
        $this->assertNotNull($other->fresh());
    }

    public function test_http_preview_confirm_and_replay_preserve_filters_without_preview_consumption(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $target = $this->relic($character);
        $source = $this->relic($character);
        $this->login($character);
        $input = ['relic_id' => $target->id, 'source_relics' => [$source->id], 'request_uuid' => $this->uuid(),
            'equipment_id' => $body->id, 'workshop_tab' => 'sets', 'gear_kind' => 'weapon', 'effect' => 'stat_str'];
        $response = $this->post(route('nameless-workshop.act', 'preview-relic-growth'), $input)->assertOk()
            ->assertViewIs('nameless-workshop.relic-growth-confirm')->assertSee('素材を投入して進捗を保存する');
        $preview = $response->viewData('preview');
        $this->assertSame(0, $target->fresh()->growth_progress);
        $this->assertNotNull($source->fresh());
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
        $confirmed = $input + ['confirmed' => 1, 'confirmation_hash' => $preview['confirmation_hash']];
        $response = $this->post(route('nameless-workshop.act', 'grow-relic'), $confirmed)->assertRedirect()->assertSessionHas('status');
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('grow_relic='.$target->id, $location);
        $this->assertStringContainsString('gear_kind=weapon', $location);
        $this->assertStringContainsString('effect=stat_str', $location);
        $this->assertStringEndsWith('#relic-growth', $location);
        $this->post(route('nameless-workshop.act', 'grow-relic'), $confirmed)->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, $target->fresh()->growth_progress);
        $this->assertDatabaseCount('nameless_workshop_operations', 1);
        $this->get($location)->assertOk()->assertSee('素材あと')->assertSee('育成進捗');
    }

    public function test_http_requires_confirmation_and_distinct_valid_materials(): void
    {
        $character = $this->character();
        $this->body($character);
        $target = $this->relic($character); $source = $this->relic($character);
        $this->login($character);
        $input = ['relic_id' => $target->id, 'source_relics' => [$source->id], 'request_uuid' => $this->uuid()];
        $this->post(route('nameless-workshop.act', 'grow-relic'), $input)->assertSessionHasErrors(['confirmed', 'confirmation_hash']);
        $this->post(route('nameless-workshop.act', 'preview-relic-growth'), array_replace($input, ['source_relics' => [$source->id, $source->id]]))->assertSessionHasErrors('source_relics.0');
        $this->post(route('nameless-workshop.act', 'preview-relic-growth'), array_replace($input, ['source_relics' => []]))->assertSessionHasErrors('source_relics');
        $this->assertNotNull($source->fresh());
        $this->assertSame(0, $target->fresh()->growth_progress);
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_foreign_get_and_disabled_feature_reject_growth(): void
    {
        $character = $this->character(); $this->body($character);
        $foreign = $this->relic($this->character());
        $target = $this->relic($character); $source = $this->relic($character);
        $this->login($character);
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'grow_relic' => $foreign->id]))->assertNotFound();
        $this->get(route('nameless-workshop.index', ['grow_relic' => ['bad']]))->assertNotFound();
        config(['nameless_relics.enabled' => false]);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertFalse($this->growth()->ready());
            $this->get(route('nameless-workshop.index', ['grow_relic' => $target->id]))->assertNotFound();
            $this->reject(fn () => $this->growth()->preview($character, $target->id, [$source->id]), '現在利用できません');
        }
        $this->app->instance('env', 'testing');
        config(['nameless_relics.enabled' => false]);
        $this->assertFalse($this->growth()->ready());
        $this->assertNotNull($source->fresh());
    }

    public function test_confirm_outside_workshop_and_used_migration_rollback_are_rejected(): void
    {
        $character = $this->character();
        $target = $this->relic($character); $source = $this->relic($character);
        $preview = $this->growth()->preview($character, $target->id, [$source->id]);
        $town = $character->current_city_id;
        $character->update(['current_city_id' => null]);
        $this->reject(fn () => $this->growth()->grow($character, $target->id, [$source->id], $preview['confirmation_hash'], $this->uuid()), '工房');
        $this->assertNotNull($source->fresh());
        $character->update(['current_city_id' => $town]);
        $this->growth()->grow($character, $target->id, [$source->id], $preview['confirmation_hash'], $this->uuid());
        $migration = require database_path('migrations/2026_10_04_060000_add_growth_progress_to_player_relics.php');
        $this->reject(fn () => $migration->down(), '進捗を保持');
        $this->assertSame(1, $target->fresh()->growth_progress);
    }

    private function growth(): NamelessRelicGrowthService
    {
        return app(NamelessRelicGrowthService::class);
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();

        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '遺物育成検証',
            'current_city_id' => $town->id, 'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 10000, 'current_mp' => 500,
            'attack_base' => 1000, 'defense_base' => 1000, 'magic_base' => 1000, 'spirit_base' => 1000, 'speed_base' => 1000, 'luck_base' => 10,
            'money' => 10000, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now()]);
    }

    private function body(Character $character): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣']);
    }

    private function relic(Character $character, int $rank = 1, string $effect = 'stat_str'): PlayerRelic
    {
        return PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => $effect, 'rank' => $rank]);
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function reject(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('拒否されるべき操作が成功しました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
