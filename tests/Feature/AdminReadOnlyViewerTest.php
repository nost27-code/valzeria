<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminViewerAccess;
use App\Models\User;
use App\Services\Admin\AdminViewerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminReadOnlyViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['admin_viewer.email' => 'dot-viewer@example.com',
            'admin_viewer.password_hash' => Hash::make('read-only-password'),
            'admin_viewer.tables' => ['users' => '登録ユーザー', 'public_logs' => '個人チャット'],
            'admin_viewer.settings' => ['battle' => '戦闘設定']]);
    }

    public function test_viewer_login_is_separate_and_never_authenticates_a_player_or_admin(): void
    {
        $this->get('/admin/readonly')->assertRedirect('/admin/readonly/login');
        $this->post('/admin/readonly/login', ['email' => 'dot-viewer@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Auth::check());
        $this->post('/admin/readonly/login', ['email' => 'dot-viewer@example.com', 'password' => 'read-only-password'])
            ->assertRedirect('/admin/readonly')->assertSessionHas('admin_viewer.fingerprint');
        $this->assertFalse(Auth::check());
        $this->get('/admin/readonly')->assertOk()->assertSee('登録ユーザー');
        $this->get('/admin')->assertRedirect('/');
        $this->post('/admin/equipment-market/1/cancel')->assertRedirect('/');
        $this->post('/admin/readonly/logout')->assertRedirect('/admin/readonly/login');
        $this->get('/admin/readonly')->assertRedirect('/admin/readonly/login');
    }

    public function test_disabled_or_rotated_credentials_revoke_existing_sessions(): void
    {
        $this->withSession(['admin_viewer.fingerprint' => AdminViewerAccess::fingerprint()]);
        config(['admin_viewer.password_hash' => Hash::make('rotated-password')]);
        $this->get('/admin/readonly')->assertRedirect('/admin/readonly/login');
        config(['admin_viewer.email' => '', 'admin_viewer.password_hash' => '']);
        $this->post('/admin/readonly/login', ['email' => 'dot-viewer@example.com', 'password' => 'read-only-password'])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Auth::check());
    }

    public function test_reading_preserves_data_and_reads_only_the_selected_table(): void
    {
        $user = User::factory()->create(['name' => '<script>danger()</script>', 'email' => 'private@example.com', 'remember_token' => 'private-token']);
        $before = DB::table('users')->get()->toJson();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $this->withSession(['admin_viewer.fingerprint' => AdminViewerAccess::fingerprint()]);
        $this->get('/admin/readonly')->assertOk();
        $this->assertFalse((bool) preg_grep('/from ["`]?users\b/i', $queries));
        $queries = [];
        $this->get('/admin/readonly/data/users?field=id&value='.$user->id)
            ->assertOk()->assertSee('private@example.com')->assertSee('&lt;script&gt;danger()&lt;/script&gt;', false)
            ->assertDontSee('<script>danger()</script>', false)->assertDontSee($user->password)
            ->assertDontSee('private-token')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertFalse((bool) preg_grep('/^(insert|update|delete|replace)\b/i', $queries));
        $this->assertFalse((bool) preg_grep('/from ["`]?(public_logs|battle_logs|characters)\b/i', $queries));
        $this->assertSame($before, DB::table('users')->get()->toJson());
    }

    public function test_table_names_search_fields_and_settings_are_allowlisted(): void
    {
        $this->withSession(['admin_viewer.fingerprint' => AdminViewerAccess::fingerprint()]);
        $this->get('/admin/readonly/data/sessions')->assertNotFound();
        $this->get('/admin/readonly/data/users?field=password&value=test')->assertStatus(422);
        $this->get('/admin/readonly/data/users?field=id%20OR%201=1&value=test')->assertStatus(422);
        $this->get('/admin/readonly/settings/services')->assertNotFound();
        $this->get('/admin/readonly/settings/admin_viewer')->assertNotFound();
        $this->get('/admin/readonly/settings/battle')->assertOk();
        $this->post('/admin/readonly/data/users', ['name' => 'overwrite'])->assertStatus(405);
        $this->delete('/admin/readonly/data/users')->assertStatus(405);
        $this->get('/admin/readonly/attachments/unknown/1')->assertNotFound();
    }

    public function test_nested_json_credentials_are_redacted_and_text_is_preserved(): void
    {
        $result = app(AdminViewerService::class)->sanitize(['email' => 'private@example.com',
            'password' => 'hash', 'metadata' => '{"message":"private chat","access_token":"do-not-show","nested":{"api_key":"key","amount":123}}']);
        $this->assertSame(['email' => 'private@example.com', 'metadata' => ['message' => 'private chat', 'nested' => ['amount' => 123]]], $result);
    }

    public function test_original_dashboard_reports_are_available_only_after_viewer_login(): void
    {
        $this->get('/admin/readonly/reports/dashboard/txt')->assertRedirect('/admin/readonly/login');
        $this->withSession(['admin_viewer.fingerprint' => AdminViewerAccess::fingerprint()]);
        $this->get('/admin/readonly/reports/dashboard/txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->get('/admin/readonly/reports/dashboard/csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get('/admin/readonly/reports/dashboard/delete')->assertNotFound();
        $this->assertFalse(Auth::check());
    }
}
