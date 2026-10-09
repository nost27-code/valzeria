<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\NamelessEquipmentDiscovery;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\NamelessEquipmentBulkDiscardService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NamelessBulkOperationsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\SharedStorageFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
    }

    public function test_preview_cancel_and_confirm_preserve_selection_and_discovery_and_replay(): void
    {
        $character = $this->character();
        $a = $this->body($character, ['custom_name' => '星巡り', 'forge_level' => 5, 'growth_exp' => 24]);
        $b = $this->body($character, ['growth_exp' => 8]);
        NamelessEquipmentDiscovery::query()->create(['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣']);
        $this->login($character);
        $payload = ['equipment_ids' => [$a->id, $b->id], 'equipment_id' => $a->id, 'request_uuid' => (string) Str::uuid(),
            'workshop_tab' => 'workshop', 'gear_query' => '剣', 'gear_sort' => 'newest'];
        $before = $character->fresh()->getAttributes();
        $response = $this->post(route('nameless-workshop.act', 'preview-discard-equipment-bulk'), $payload)
            ->assertOk()->assertViewIs('nameless-workshop.equipment-discard-confirm')->assertSee('合計32')
            ->assertSee('破棄せず選び直す');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $preview = $response->viewData('preview');
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
        $this->assertDatabaseCount('player_nameless_equipments', 3);
        $cancel = $this->get(route('nameless-workshop.index', ['equipment' => $a->id, 'gear_query' => '剣', 'gear_sort' => 'newest']))->assertOk();
        $this->assertEqualsCanonicalizing(array_map('strval', [$a->id, $b->id]), $cancel->viewData('discardSelectedIds'));
        $payload += ['confirmation_hash' => $preview['confirmation_hash']];
        $this->post(route('nameless-workshop.act', 'discard-equipment-bulk'), $payload)->assertSessionHasErrors('confirmed');
        $this->assertNotNull($a->fresh());
        $payload['confirmed'] = 1;
        $this->post(route('nameless-workshop.act', 'discard-equipment-bulk'), $payload)->assertRedirect()
            ->assertSessionHas('status', '2個の名もなき武具を破棄しました。発見記録は残ります。');
        $this->assertNull($a->fresh());
        $this->assertNull($b->fresh());
        $this->assertDatabaseCount('nameless_equipment_discoveries', 1);
        $this->assertDatabaseCount('nameless_workshop_operations', 1);
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->post(route('nameless-workshop.act', 'discard-equipment-bulk'), $payload)->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseCount('nameless_workshop_operations', 1);
        $this->assertCount(2, NamelessWorkshopOperation::query()->firstOrFail()->result['discarded_equipment']);
    }

    public function test_ineligible_and_other_owned_items_are_rejected_without_partial_deletion(): void
    {
        $character = $this->character();
        $valid = $this->body($character);
        $other = $this->body($this->character());
        $cases = [
            ['acquisition_source' => 'starter'], ['is_locked' => true], ['is_equipped' => true], [],
        ];
        foreach ($cases as $index => $attributes) {
            $invalid = $this->body($character, $attributes);
            if ($index === 3) {
                PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1,
                    'nameless_equipment_id' => $invalid->id, 'slot_number' => 1]);
            }
            $this->reject(fn () => $this->bulk()->preview($character, [$valid->id, $invalid->id]), '破棄できません');
            $this->reject(fn () => $this->bulk()->discard($character, [$valid->id, $invalid->id], str_repeat('0', 64), (string) Str::uuid()), '破棄できません');
            $this->assertNotNull($invalid->fresh());
        }
        $this->reject(fn () => $this->bulk()->preview($character, [$valid->id, $other->id]), '所持していません');
        $this->assertNotNull($valid->fresh());
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_deleted_selected_body_returns_to_a_working_selection_page(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $b = $this->body($character);
        $preview = $this->bulk()->preview($character, [$a->id, $b->id]);
        $a->delete();
        $this->login($character);
        $response = $this->post(route('nameless-workshop.act', 'discard-equipment-bulk'), ['equipment_ids' => [$a->id, $b->id],
            'equipment_id' => $a->id, 'request_uuid' => (string) Str::uuid(), 'confirmed' => 1, 'workshop_tab' => 'workshop',
            'confirmation_hash' => $preview['confirmation_hash']])->assertRedirect()->assertSessionHas('error');
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('所持していません');
        $this->assertNotNull($b->fresh());
    }

    public function test_nested_input_validation_returns_to_the_list_without_a_server_error(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $this->login($character);
        $url = route('nameless-workshop.index', ['equipment' => $a->id]);
        $this->from($url)->post(route('nameless-workshop.act', 'preview-discard-equipment-bulk'),
            ['equipment_ids' => [[1]], 'request_uuid' => (string) Str::uuid()])->assertSessionHasErrors('equipment_ids.0');
        $this->get($url)->assertOk();
        $this->assertNotNull($a->fresh());
    }

    public function test_actual_views_render_bulk_controls_and_optional_isolated_browser_fixtures(): void
    {
        $character = $this->character();
        $a = $this->body($character, ['custom_name' => str_repeat('A', 32)]);
        $b = $this->body($character, ['forge_level' => 4, 'growth_exp' => 24]);
        $relics = collect([1, 1, 2, 3, 4, 4])->map(fn ($rank) => PlayerRelic::query()->create([
            'character_id' => $character->id, 'effect_key' => $rank < 4 ? 'stat_str' : 'stat_mag', 'rank' => $rank]));
        $this->login($character);
        $workshop = $this->get(route('nameless-workshop.index', ['equipment' => $a->id]))->assertOk()->assertSee('data-relic-bulk-select', false);
        $growth = $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'grow_relic' => $relics->first()->id]))->assertOk()->assertSee('必要数まで一括選択');
        $confirm = $this->post(route('nameless-workshop.act', 'preview-discard-equipment-bulk'), ['equipment_ids' => [$a->id, $b->id],
            'equipment_id' => $a->id, 'workshop_tab' => 'workshop', 'request_uuid' => (string) Str::uuid()])->assertOk()->assertSee('破棄しています');
        if (getenv('NAMELESS_BULK_UI_FIXTURE') === '1') {
            $directory = base_path('scratch/nameless-bulk-20261009/ui');
            if (! is_dir($directory)) mkdir($directory, 0777, true);
            foreach (['workshop' => $workshop, 'growth' => $growth, 'confirm' => $confirm] as $name => $response) {
                // Render the real Controller/Blade with disposable SQLite data, and keep browser submissions on the fixture host.
                $html = preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', $response->getContent());
                $html = preg_replace('/action="[^"]*"/', 'action="/submitted.html"', $html);
                $html = str_replace('</body>', '<script src="/livewire.js" data-csrf="fixture" data-update-uri="/fixture-update" data-navigate-once="true"></script></body>', $html);
                file_put_contents($directory.'/'.$name.'.html', $html);
            }
            copy(base_path('vendor/livewire/livewire/dist/livewire.js'), $directory.'/livewire.js');
            file_put_contents($directory.'/submitted.html', '<!doctype html><meta charset="utf-8"><p>隔離fixtureの送信先です。実データは変更しません。</p><a href="/confirm.html">確認へ戻る</a>');
        }
    }

    public function test_state_changes_after_confirmation_stop_all_items(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $b = $this->body($character);
        foreach (['is_locked' => true, 'is_equipped' => true, 'revision' => 1, 'growth_exp' => 4, 'forge_level' => 1, 'custom_name' => '変化', 'base_power' => 11, 'power_per_level' => 12] as $column => $value) {
            $preview = $this->bulk()->preview($character, [$a->id, $b->id]);
            $original = $b->fresh()->getAttribute($column);
            $b->update([$column => $value]);
            $this->reject(fn () => $this->bulk()->discard($character, [$a->id, $b->id], $preview['confirmation_hash'], (string) Str::uuid()),
                in_array($column, ['is_locked', 'is_equipped']) ? '破棄できません' : '状態が変わりました');
            $this->assertNotNull($a->fresh());
            $this->assertNotNull($b->fresh());
            $b->update([$column => $original]);
        }
        $preview = $this->bulk()->preview($character, [$a->id, $b->id]);
        PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1,
            'nameless_equipment_id' => $b->id, 'slot_number' => 1]);
        $this->reject(fn () => $this->bulk()->discard($character, [$a->id, $b->id], $preview['confirmation_hash'], (string) Str::uuid()), '破棄できません');
        $this->assertNotNull($a->fresh());
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_invalid_ids_hash_and_changed_uuid_payload_are_rejected(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $b = $this->body($character);
        foreach ([[], [$a->id, (string) $a->id], [0], [[1]], range(1, 301)] as $ids) {
            $this->reject(fn () => $this->bulk()->preview($character, $ids), $ids === [] || count($ids) > 300 ? '選んでください' : '重複せず');
        }
        $this->reject(fn () => $this->bulk()->discard($character, [$a->id], str_repeat('f', 64), (string) Str::uuid()), '状態が変わりました');
        $preview = $this->bulk()->preview($character, [$a->id]);
        $uuid = (string) Str::uuid();
        $this->bulk()->discard($character, [$a->id], $preview['confirmation_hash'], $uuid);
        $this->reject(fn () => $this->bulk()->discard($character, [$b->id], $preview['confirmation_hash'], $uuid), '内容を変更');
        $this->assertNotNull($b->fresh());
    }

    public function test_ledger_failure_rolls_back_all_deletions(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $b = $this->body($character);
        $preview = $this->bulk()->preview($character, [$a->id, $b->id]);
        NamelessWorkshopOperation::creating(function () { throw new RuntimeException('台帳保存失敗'); });
        try {
            $this->reject(fn () => $this->bulk()->discard($character, [$a->id, $b->id], $preview['confirmation_hash'], (string) Str::uuid()), '台帳保存失敗');
        } finally {
            NamelessWorkshopOperation::flushEventListeners();
        }
        $this->assertNotNull($a->fresh());
        $this->assertNotNull($b->fresh());
        $this->assertDatabaseCount('nameless_workshop_operations', 0);
    }

    public function test_full_inventory_is_recovered_and_preview_query_count_does_not_grow_per_item(): void
    {
        $character = $this->character();
        $bodies = collect(range(1, 59))->map(fn () => $this->body($character));
        $this->reserveEquipmentSlots($character, 0);
        $this->assertStringContainsString('所持枠がいっぱい', app(NamelessWorkshopService::class)->inventoryBlockReason($character));
        $this->bulk()->preview($character, [$bodies->first()->id]); // readiness warmup
        $counts = [];
        $durations = [];
        foreach ([[$bodies->first()->id], $bodies->pluck('id')->all()] as $ids) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $start = hrtime(true);
            $preview = $this->bulk()->preview($character, $ids);
            $durations[] = (hrtime(true) - $start) / 1000000;
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[0], $counts[1]);
        if (getenv('NAMELESS_BULK_UI_FIXTURE') === '1') {
            file_put_contents(base_path('scratch/nameless-bulk-20261009/performance.json'), json_encode([
                'database' => 'isolated SQLite', 'selected_counts' => [1, 59], 'sql_counts' => $counts, 'duration_ms' => $durations,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->bulk()->discard($character, $bodies->pluck('id')->all(), $preview['confirmation_hash'], (string) Str::uuid());
        $this->assertNull(app(NamelessWorkshopService::class)->inventoryBlockReason($character));
        $this->assertDatabaseCount('player_nameless_equipments', 1);
    }

    public function test_filtered_bulk_list_and_relic_selection_exclude_protected_assets(): void
    {
        $character = $this->character();
        $a = $this->body($character, ['custom_name' => str_repeat('A', 32)]);
        $this->body($character, ['equipment_type' => '杖']);
        $this->body($character, ['is_locked' => true]);
        $free = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1]);
        PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1]);
        $locked = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1, 'is_locked' => true]);
        $progress = PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1, 'growth_progress' => 1]);
        $this->login($character);
        $response = $this->get(route('nameless-workshop.index', ['equipment' => $a->id, 'gear_type' => '剣']))->assertOk()
            ->assertSee('表示中を一括選択')->assertSee('選択をすべて解除')->assertSee('名もなき武具をまとめて整理する');
        $this->assertSame([$a->id], $response->viewData('discardableEquipment')->keys()->all());
        $this->assertContains($free->id, $response->viewData('forgeRelics')->pluck('id')->all());
        $this->assertNotContains($locked->id, $response->viewData('forgeRelics')->pluck('id')->all());
        $this->assertNotContains($progress->id, $response->viewData('forgeRelics')->pluck('id')->all());
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'grow_relic' => $free->id]))->assertOk()->assertSee('必要数まで一括選択');
    }

    public function test_large_selection_restoration_is_limited_to_the_request_budget(): void
    {
        $character = $this->character();
        $bodies = collect(range(1, 301))->map(fn () => $this->body($character));
        $this->login($character);
        $response = $this->withSession(['nameless_discard_selection.'.$character->id => $bodies->pluck('id')->all()])
            ->get(route('nameless-workshop.index'))->assertOk()->assertSee('一度に300個まで選べます');
        $this->assertCount(300, $response->viewData('discardSelectedIds'));
        $this->assertCount(301, $response->viewData('discardableEquipment'));
        $this->assertStringContainsString('choices.slice(0, maxSelected)', $response->getContent());
    }

    public function test_disabled_feature_and_outside_town_cannot_discard_existing_assets(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $preview = $this->bulk()->preview($character, [$a->id]);
        config(['nameless_relics.enabled' => false]);
        $this->reject(fn () => $this->bulk()->discard($character, [$a->id], $preview['confirmation_hash'], (string) Str::uuid()), '現在利用できません');
        $this->login($character);
        $this->post(route('nameless-workshop.act', 'discard-equipment-bulk'), [])->assertNotFound();
        config(['nameless_relics.enabled' => true]);
        $character->update(['current_city_id' => 1]);
        $this->reject(fn () => $this->bulk()->discard($character, [$a->id], $preview['confirmation_hash'], (string) Str::uuid()), '工房街');
        $this->assertNotNull($a->fresh());
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '一括整理検証', 'current_city_id' => $town->id,
            'hp_base' => 1000, 'mp_base' => 100, 'current_hp' => 1000, 'current_mp' => 100, 'money' => 10000]);
        $this->body($character, ['acquisition_source' => 'starter', 'is_locked' => true]);

        return $character;
    }

    private function body(Character $character, array $attributes = []): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::query()->create($attributes + ['character_id' => $character->id, 'kind' => 'weapon',
            'equipment_type' => '剣', 'acquisition_source' => 'ruin', 'forge_level' => 0, 'growth_exp' => 0, 'revision' => 0]);
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
    }

    private function bulk(): NamelessEquipmentBulkDiscardService
    {
        return app(NamelessEquipmentBulkDiscardService::class);
    }

    private function reject(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('拒否されるべき操作が成功しました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
