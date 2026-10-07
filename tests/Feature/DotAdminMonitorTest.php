<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminViewerAccess;
use App\Models\BugReport;
use App\Models\Character;
use App\Models\CharacterIconDesignMessage;
use App\Models\CharacterIconDesignRequest;
use App\Models\PublicLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DotAdminMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['admin_viewer.email' => 'dot@example.com', 'admin_viewer.password_hash' => Hash::make('viewer-password')]);
    }

    public function test_every_monitor_route_requires_the_dedicated_login(): void
    {
        foreach (['overview', 'players', 'chat', 'reports', 'icon-design'] as $section) {
            $this->get('/admin/readonly/monitor/'.$section)->assertRedirect('/admin/readonly/login');
        }
        $this->get('/admin/readonly/icon-design/1')->assertRedirect('/admin/readonly/login');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/readonly/monitor')->assertRedirect('/admin/readonly/login');
    }

    public function test_viewer_login_discards_existing_admin_and_remember_me_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'remember_token' => 'remember-secret']);
        $recaller = Auth::guard('web')->getRecallerName();
        $this->actingAs($admin)->withCookie($recaller, $admin->id.'|remember-secret|'.$admin->password);
        $this->withSession(['current_character_id' => 99])
            ->post('/admin/readonly/login', ['email' => 'dot@example.com', 'password' => 'viewer-password'])
            ->assertRedirect('/admin/readonly')->assertCookieExpired($recaller)
            ->assertSessionMissing('current_character_id');
        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/');
        $this->post('/admin/equipment-market/1/cancel')->assertRedirect('/');
        $this->get('/admin/readonly/monitor')->assertOk();
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('remember-secret', $admin->fresh()->remember_token);
    }

    public function test_reports_and_chat_are_escaped_filtered_and_never_marked_read(): void
    {
        $character = $this->character();
        $report = BugReport::create(['character_id' => $character->id, 'body' => '<script>bad()</script> 報告本文', 'status' => 'new', 'kind' => 'bug']);
        PublicLog::create(['character_id' => $character->id, 'type' => 'chat', 'message' => '公開発言', 'receiver_id' => null]);
        PublicLog::create(['character_id' => $character->id, 'type' => 'chat', 'message' => '個人チャット非表示', 'receiver_id' => $character->id]);
        PublicLog::create(['character_id' => $character->id, 'type' => 'guild', 'message' => 'ギルド発言非表示', 'receiver_id' => null]);
        PublicLog::create(['character_id' => $character->id, 'type' => 'admin', 'message' => '管理人の公開発言', 'receiver_id' => null]);
        $this->viewer();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $response = $this->get('/admin/readonly/monitor/reports?status=new&q=報告本文')
            ->assertOk()->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)
            ->assertDontSee('<script>bad()</script>', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->preview('reports', $response->getContent());
        $response = $this->get('/admin/readonly/monitor/chat')->assertOk()
            ->assertSee('公開発言')->assertSee('管理人の公開発言')
            ->assertDontSee('個人チャット非表示')->assertDontSee('ギルド発言非表示');
        $this->preview('chat', $response->getContent());
        $this->assertNoWrites($queries);
        $this->assertSame('new', $report->fresh()->status);
        $this->assertNull($report->fresh()->read_at);
    }

    public function test_icon_inquiries_include_new_submissions_and_unread_updates_without_mutation(): void
    {
        $character = $this->character();
        $submitted = $this->design($character, 'submitted');
        $unread = $this->design($character, 'in_progress');
        $read = $this->design($character, 'completed');
        $draft = $this->design($character, 'draft', false);
        $message = CharacterIconDesignMessage::create(['character_icon_design_request_id' => $unread->id, 'sender_type' => 'player', 'body' => '新しい問い合わせ <img src=x onerror=bad()>']);
        CharacterIconDesignMessage::create(['character_icon_design_request_id' => $read->id, 'sender_type' => 'player', 'body' => '確認済みの連絡', 'read_by_admin_at' => now()]);
        $before = DB::table('character_icon_design_messages')->orderBy('id')->get()->toJson();
        $this->viewer();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $response = $this->get('/admin/readonly/monitor/icon-design')->assertOk()
            ->assertSee('依頼 #'.$submitted->id)->assertSee('依頼 #'.$unread->id)
            ->assertDontSee('依頼 #'.$read->id)->assertDontSee('依頼 #'.$draft->id);
        $this->preview('icon-list', $response->getContent());
        $response = $this->get('/admin/readonly/icon-design/'.$unread->id)->assertOk()
            ->assertSee('新しい問い合わせ')->assertSee('&lt;img src=x onerror=bad()&gt;', false)
            ->assertDontSee('<img src=x onerror=bad()>', false)->assertSee('かっこよさ重視')
            ->assertSee('翼と青い宝石')->assertSee('未読');
        $this->preview('icon-detail', $response->getContent());
        $this->get('/admin/readonly/icon-design/'.$draft->id)->assertNotFound();
        $this->get('/admin/readonly/monitor/icon-design?only_new=0')->assertOk()->assertSee('依頼 #'.$read->id);
        $this->assertNoWrites($queries);
        $this->assertSame($before, DB::table('character_icon_design_messages')->orderBy('id')->get()->toJson());
        $this->assertNull($message->fresh()->read_by_admin_at);
        $this->assertSame('submitted', $submitted->fresh()->status);
    }

    public function test_pagination_and_exact_end_date_preserve_the_filters(): void
    {
        $character = $this->character();
        for ($i = 0; $i < 51; $i++) {
            PublicLog::create(['character_id' => $character->id, 'type' => 'chat', 'message' => '検索対象 '.$i, 'created_at' => '2026-10-07 23:59:59', 'receiver_id' => null]);
        }
        PublicLog::create(['character_id' => $character->id, 'type' => 'chat', 'message' => '翌日は対象外', 'created_at' => '2026-10-08 00:00:00', 'receiver_id' => null]);
        $this->viewer();
        $this->get('/admin/readonly/monitor/chat?q=検索対象&from=2026-10-07&to=2026-10-07')
            ->assertOk()->assertSee('次の50件')->assertSee('page=2', false)->assertDontSee('翌日は対象外');
        $this->get('/admin/readonly/monitor/chat?q=検索対象&from=2026-10-07&to=2026-10-07&page=2')
            ->assertOk()->assertSee('検索対象 0')->assertDontSee('検索対象 50');
    }

    public function test_monitor_does_not_expose_email_password_or_editing_routes(): void
    {
        $this->character();
        $this->viewer();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $response = $this->get('/admin/readonly/monitor/players')->assertOk()->assertSee('dot確認用冒険者')
            ->assertDontSee('private-player@example.com')->assertDontSee('remember-secret');
        $this->preview('players', $response->getContent());
        $response = $this->get('/admin/readonly/monitor')->assertOk()->assertSee('キャラ制作の新着依頼');
        $this->preview('overview', $response->getContent());
        $this->get('/admin/readonly')->assertOk()->assertSee('キャラ制作の問い合わせ');
        $this->assertNoWrites($queries);
        $this->post('/admin/readonly/monitor/reports', ['status' => 'resolved'])->assertStatus(405);
        $this->patch('/admin/readonly/icon-design/1', ['status' => 'completed'])->assertStatus(405);
        $this->delete('/admin/readonly/monitor/chat')->assertStatus(405);
    }

    public function test_credentials_rotation_and_malformed_filters_fail_closed(): void
    {
        $this->viewer();
        foreach (['page=-1', 'page=10001', 'q[]=bad', 'from=2026-02-30', 'only_new=other'] as $query) {
            $this->get('/admin/readonly/monitor/chat?'.$query)->assertStatus(422);
        }
        $this->get('/admin/readonly/monitor/unknown')->assertNotFound();
        config(['admin_viewer.password_hash' => Hash::make('new-password')]);
        $this->get('/admin/readonly/monitor/chat')->assertRedirect('/admin/readonly/login');
    }

    private function viewer(): void
    {
        $this->withSession(['admin_viewer.fingerprint' => AdminViewerAccess::fingerprint()]);
    }

    private function character(): Character
    {
        $user = User::factory()->create(['email' => 'private-player@example.com', 'remember_token' => 'remember-secret']);
        return Character::create(['user_id' => $user->id, 'name' => 'dot確認用冒険者', 'level' => 42, 'last_seen_at' => now()]);
    }

    private function design(Character $character, string $status, bool $submitted = true): CharacterIconDesignRequest
    {
        return CharacterIconDesignRequest::create(['character_id' => $character->id, 'status' => $status,
            'submitted_at' => $submitted ? now() : null, 'form_data' => ['priority' => 'cool', 'must_have' => '翼と青い宝石']]);
    }

    private function assertNoWrites(array $queries): void
    {
        $this->assertSame([], array_values(preg_grep('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $queries)));
    }

    private function preview(string $name, string $html): void
    {
        $directory = getenv('DOT_VIEWER_PREVIEW_DIRECTORY');
        if ($directory) {
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
