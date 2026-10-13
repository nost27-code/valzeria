<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\User;
use App\Services\JobArtService;
use App\Services\JobArtUpdateHistoryService;
use App\Services\JobArtUpdateReadService;
use App\Services\JobArtV2CardDescriptionCatalog;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class JobArtUpdateHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $updates = config('job_art_updates');
        foreach ($updates as &$versions) { foreach ($versions as &$update) { $update['status'] = 'unreleased'; $update['published_at'] = null; } }
        config(['job_art_updates' => $updates]);
    }

    private function login(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'name' => '履歴検証', 'role' => 'user']);
        $user->exists = true;
        $user->shouldReceive('currentCharacter')->andReturnNull();
        $this->actingAs($user);
    }

    public function test_history_shows_three_arts_even_without_learning_and_never_marks_read(): void
    {
        $this->login();
        $reads = Mockery::mock(JobArtUpdateReadService::class);
        $reads->shouldNotReceive('markRead');
        $this->app->instance(JobArtUpdateReadService::class, $reads);
        $arts = Mockery::mock(JobArtService::class);
        $arts->shouldNotReceive('availableArts');
        $this->app->instance(JobArtService::class, $arts);
        $this->get(route('job-arts.updates'))->assertOk()
            ->assertSee('戦技更新履歴')->assertSee('不落要塞')->assertSee('大商隊の守護')->assertSee('金剛不壊')
            ->assertSee('変更前')->assertSee('変更後')->assertSee('調整理由')->assertSee('公開準備中')
            ->assertSee('最大HPの3%')->assertSee('複数回当たる攻撃も、まとめて1つの行動として扱い、回復は1回だけ発生する。')
            ->assertDontSee('2026-10-12-defense-v1');
    }

    public function test_only_published_changes_are_visible_outside_local_testing(): void
    {
        $history = new JobArtUpdateHistoryService;
        $this->assertCount(0, $history->forDisplay());
        config(['job_art_updates.15:9:不落要塞.0.status' => 'published',
            'job_art_updates.15:9:不落要塞.0.published_at' => '2026-10-13']);
        $display = $history->forDisplay();
        $this->assertCount(1, $display);
        $this->assertSame('不落要塞', $display->first()['entries']->first()['name']);
        $this->assertCount(1, $display->first()['entries']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->login();
        $this->get(route('job-arts.updates'))->assertOk()->assertSee('公開済み')->assertSee('公開日：2026-10-13')
            ->assertDontSee('大商隊の守護')->assertDontSee('金剛不壊')->assertDontSee('公開準備中');
    }

    public function test_prepend_keeps_old_history_and_read_marker_uses_latest_revision(): void
    {
        $old = config('job_art_updates.15:9:不落要塞.0');
        $new = $old;
        $new['revision'] = 'history-test-next';
        $new['date'] = '2026-10-13';
        $new['after'] = '次の版の効果解説。';
        config(['job_art_updates.15:9:不落要塞' => [$new, $old]]);
        $history = (new JobArtUpdateHistoryService)->forDisplay(true);
        $this->assertSame(['2026-10-13', '2026-10-12'], $history->pluck('date')->all());
        $this->assertCount(3, $history->last()['entries']);
        $this->assertSame($old['after'], $history->last()['entries']->first()['after']);
        $this->assertSame($new['after'], $history->first()['entries']->first()['after']);
        $skill = new Skill(['job_id' => 15, 'learn_rank' => 9, 'name' => '不落要塞', 'skill_type' => 'job_art']);
        $this->assertSame(['revision' => 'history-test-next', 'date' => '2026-10-13'], (new JobArtUpdateReadService)->updateFor($skill));
    }

    public function test_pagination_preserves_old_records_without_extra_database_queries(): void
    {
        $this->login();
        $entry = config('job_art_updates.15:9:不落要塞.0');
        $entries = [];
        for ($day = 20; $day >= 1; $day--) {
            $copy = $entry;
            $copy['revision'] = 'history-test-'.$day;
            $copy['date'] = sprintf('2026-09-%02d', $day);
            $entries[] = $copy;
        }
        config(['job_art_updates' => ['15:9:不落要塞' => $entries]]);
        DB::enableQueryLog();
        $this->get(route('job-arts.updates'))->assertOk()->assertSee('2026-09-20')->assertSee('過去の記録')
            ->assertDontSee('2026-09-10');
        $this->get(route('job-arts.updates', ['page' => 2]))->assertOk()->assertSee('2026-09-10')->assertSee('新しい記録')
            ->assertDontSee('2026-09-20');
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_history_has_empty_state_and_escapes_text(): void
    {
        $this->login();
        config(['job_art_updates' => []]);
        $this->get(route('job-arts.updates'))->assertOk()->assertSee('更新記録はまだありません');
        $entry = ['revision' => 'test', 'date' => '2026-10-13', 'status' => 'unreleased', 'published_at' => null,
            'before' => '<script>alert(1)</script>', 'after' => '<script>alert(2)</script>を-12する。', 'reason' => '理由', 'unchanged' => '条件維持'];
        config(['job_art_updates' => ['15:9:テスト戦技' => [$entry]]]);
        $this->get(route('job-arts.updates'))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(2)</script>', false);
    }

    public function test_history_requires_login(): void
    {
        $this->get(route('job-arts.updates'))->assertRedirect('/');
    }

    public function test_current_history_after_matches_canonical_card_copy_and_shared_formatting(): void
    {
        $this->login();
        $response = $this->get(route('job-arts.updates'))->assertOk();
        foreach (config('job_art_updates') as $key => $entries) {
            [$jobId, $rank, $name] = explode(':', $key, 3);
            $skill = new Skill(['job_id' => (int) $jobId, 'learn_rank' => (int) $rank, 'name' => $name]);
            $canonical = app(JobArtV2CardDescriptionCatalog::class)->defaultDescription($skill);
            $this->assertNotNull($canonical);
            $this->assertSame($canonical, $entries[0]['after'], 'Current update copy diverged from the card: '.$name);
            $response->assertSee(view('job-arts.partials.effect-text', ['text' => $canonical])->render(), false);
        }
    }

    public function test_unreleased_future_revision_never_replaces_published_unread_revision(): void
    {
        $old = config('job_art_updates.15:9:不落要塞.0');
        $old['status'] = 'published';
        $new = $old;
        $new['revision'] = 'future-unreleased';
        $new['date'] = '2026-10-13';
        $new['status'] = 'unreleased';
        $old['name'] = '旧名称の記録';
        config(['job_art_updates.15:9:不落要塞' => [$new, $old]]);
        $skill = new Skill(['job_id' => 15, 'learn_rank' => 9, 'name' => '不落要塞', 'skill_type' => 'job_art']);
        $reads = new JobArtUpdateReadService;
        $this->assertSame('future-unreleased', $reads->updateFor($skill)['revision']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame($old['revision'], $reads->updateFor($skill)['revision']);
        $display = (new JobArtUpdateHistoryService)->forDisplay();
        $this->assertSame('旧名称の記録', $display->first()['entries']->first()['name']);
        config(['job_art_updates.15:9:不落要塞' => [$new]]);
        $this->assertNull($reads->updateFor($skill));
    }
}
