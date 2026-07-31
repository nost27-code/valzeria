<?php

namespace Tests\Feature;

use App\Livewire\Admin\TopUpdateManager;
use App\Models\TopUpdate;
use App\Services\TownUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_sync_does_not_overwrite_an_edited_or_dismissed_draft(): void
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
                'is_dismissed' => true,
            ]);

        $this->assertSame(0, $service->syncDraftsFromAdminSummaries());
        $this->assertDatabaseCount('top_updates', 1);
        $this->assertDatabaseHas('top_updates', [
            'source_key' => 'editable-update',
            'body' => '管理者が整えた文言',
            'is_dismissed' => true,
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

    public function test_imported_candidate_can_be_dismissed_without_deletion_and_restored_as_draft(): void
    {
        config()->set('admin_update_summaries', []);
        $update = TopUpdate::query()->create([
            'published_on' => '2026-07-31',
            'body' => '掲載候補',
            'source_key' => 'dismissible-update',
            'source_category' => 'changed',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        Livewire::test(TopUpdateManager::class)
            ->call('delete', $update->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('top_updates', [
            'id' => $update->id,
            'is_active' => false,
            'is_dismissed' => true,
        ]);

        Livewire::test(TopUpdateManager::class)
            ->call('restore', $update->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('top_updates', [
            'id' => $update->id,
            'is_active' => false,
            'is_dismissed' => false,
        ]);
    }
}
