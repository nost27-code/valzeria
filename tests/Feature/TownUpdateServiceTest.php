<?php

namespace Tests\Feature;

use App\Livewire\Admin\TopUpdateManager;
use App\Models\GameSetting;
use App\Models\TopUpdate;
use App\Services\TownUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class TownUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-31 12:00:00', 'Asia/Tokyo'));
        TopUpdate::query()->delete();
        GameSetting::query()->where('setting_key', 'like', 'town_updates.deleted.%')->delete();
        app(TownUpdateService::class)->forgetPublishedCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_player_facing_admin_summaries_are_imported_as_unpublished_drafts(): void
    {
        config()->set('admin_update_summaries', [
            [
                'id' => 'player-update',
                'date' => '2026-07-31',
                'category' => 'added',
                'title' => '新しい機能を追加',
                'detail' => '新しい機能を利用できるようになりました。詳しい内容も確認できます。',
            ],
            [
                'id' => 'internal-update',
                'date' => '2026-07-31',
                'category' => 'internal',
                'title' => '管理機能を変更',
                'detail' => 'プレイヤーには公開しません。',
            ],
        ]);

        $created = app(TownUpdateService::class)->syncDraftsFromAdminSummaries();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('top_updates', [
            'source_key' => 'player-update',
            'body' => '新しい機能を利用できるようになりました。',
            'detail' => '詳しい内容も確認できます。',
            'is_active' => false,
            'is_dismissed' => false,
        ]);
        $this->assertDatabaseMissing('top_updates', [
            'source_key' => 'internal-update',
        ]);
    }

    public function test_sync_does_not_overwrite_an_edited_draft(): void
    {
        config()->set('admin_update_summaries', [[
            'id' => 'editable-update',
            'date' => '2026-07-31',
            'category' => 'changed',
            'title' => '自動生成文',
            'detail' => '自動生成された詳細です。',
        ]]);

        $service = app(TownUpdateService::class);
        $service->syncDraftsFromAdminSummaries();

        TopUpdate::query()
            ->where('source_key', 'editable-update')
            ->update([
                'body' => '管理者が整えた文言',
            ]);

        $this->assertSame(0, $service->syncDraftsFromAdminSummaries());
        $this->assertDatabaseCount('top_updates', 1);
        $this->assertDatabaseHas('top_updates', [
            'source_key' => 'editable-update',
            'body' => '管理者が整えた文言',
        ]);
    }

    public function test_only_active_current_updates_are_returned_in_display_order(): void
    {
        TopUpdate::query()->create([
            'published_on' => '2026-07-31',
            'body' => '2件目',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        TopUpdate::query()->create([
            'published_on' => '2026-07-31',
            'body' => '1件目',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        TopUpdate::query()->create([
            'published_on' => '2026-08-01',
            'body' => '未来のお知らせ',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        TopUpdate::query()->create([
            'published_on' => '2026-07-30',
            'body' => '除外済み',
            'sort_order' => 1,
            'is_active' => true,
            'is_dismissed' => true,
        ]);

        $updates = app(TownUpdateService::class)->published(3);

        $this->assertSame(['1件目', '2件目'], $updates->pluck('body')->all());
    }

    public function test_published_updates_do_not_restore_an_eloquent_collection_from_shared_cache(): void
    {
        Cache::put('town_updates:published', 'stale-serialized-value');
        TopUpdate::query()->create([
            'published_on' => '2026-07-31',
            'body' => 'DBから取得するお知らせ',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $updates = app(TownUpdateService::class)->published();

        $this->assertSame(['DBから取得するお知らせ'], $updates->pluck('body')->all());
    }

    public function test_imported_candidate_is_deleted_and_is_not_recreated(): void
    {
        config()->set('admin_update_summaries', [[
            'id' => 'deletable-update',
            'date' => '2026-07-31',
            'category' => 'changed',
            'title' => '削除対象の更新候補',
            'detail' => '削除対象の更新候補です。',
        ]]);

        app(TownUpdateService::class)->syncDraftsFromAdminSummaries();
        $update = TopUpdate::query()->create([
            'published_on' => '2026-07-30',
            'body' => '手動作成項目',
            'sort_order' => 20,
            'is_active' => false,
        ]);
        $imported = TopUpdate::query()->where('source_key', 'deletable-update')->firstOrFail();

        Livewire::test(TopUpdateManager::class)
            ->assertSee('削除')
            ->assertDontSee('掲載から除外')
            ->assertDontSee('下書きへ戻す')
            ->call('delete', $imported->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('top_updates', [
            'id' => $imported->id,
        ]);
        $this->assertDatabaseHas('game_settings', [
            'setting_key' => 'town_updates.deleted.' . sha1('deletable-update'),
            'value' => '1',
        ]);
        $this->assertSame(0, app(TownUpdateService::class)->syncDraftsFromAdminSummaries());
        $this->assertDatabaseMissing('top_updates', ['source_key' => 'deletable-update']);
        $this->assertDatabaseHas('top_updates', ['id' => $update->id]);
    }

    public function test_legacy_dismissed_candidate_is_deleted_and_registered_on_sync(): void
    {
        config()->set('admin_update_summaries', [[
            'id' => 'legacy-dismissed-update',
            'date' => '2026-07-31',
            'category' => 'fixed',
            'title' => '旧掲載除外候補',
        ]]);

        $update = TopUpdate::query()->create([
            'published_on' => '2026-07-31',
            'body' => '旧掲載除外候補',
            'source_key' => 'legacy-dismissed-update',
            'source_category' => 'fixed',
            'sort_order' => 10,
            'is_active' => false,
            'is_dismissed' => true,
        ]);

        $this->assertSame(0, app(TownUpdateService::class)->syncDraftsFromAdminSummaries());

        $this->assertDatabaseMissing('top_updates', [
            'id' => $update->id,
        ]);
        $this->assertDatabaseHas('game_settings', [
            'setting_key' => 'town_updates.deleted.' . sha1('legacy-dismissed-update'),
        ]);
    }

    public function test_deleted_source_registry_is_capped_at_auto_import_limit(): void
    {
        config()->set('admin_update_summaries', []);

        foreach (range(1, 55) as $index) {
            GameSetting::query()->create([
                'setting_key' => 'town_updates.deleted.' . sha1('deleted-update-' . $index),
                'label' => '削除済み更新候補',
                'description' => null,
                'value' => '1',
                'value_type' => 'boolean',
            ]);
        }

        app(TownUpdateService::class)->syncDraftsFromAdminSummaries();

        $this->assertSame(50, GameSetting::query()
            ->where('setting_key', 'like', 'town_updates.deleted.%')
            ->count());
    }
}
