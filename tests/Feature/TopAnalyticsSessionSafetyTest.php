<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Services\TopPageAnalyticsService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class TopAnalyticsSessionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        app('session')->forgetDrivers();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests()
            {
                return false;
            }

        });
    }

    private function storedSession(?string $id = null): Store
    {
        $store = new Store(config('session.cookie'), new DatabaseSessionHandler(DB::connection(), 'sessions', 120), $id, config('session.serialization', 'php'));
        $store->start();

        return $store;
    }

    public function test_delayed_analytics_cannot_erase_new_google_login_and_still_records_user(): void
    {
        $user = User::factory()->create();
        $initial = $this->storedSession();
        $initial->put(auth()->guard()->getName(), $user->id);
        $initial->save();
        $id = $initial->getId();
        $state = null;
        $startedAt = null;

        $this->mock(TopPageAnalyticsService::class)->shouldReceive('recordEvent')->once()->andReturnUsing(
            function (Request $analyticsRequest, string $visit, string $event, array $metadata) use ($id, &$state, &$startedAt) {
                $this->assertFalse($analyticsRequest->session()->has('state'));
                // The analytics request has read its snapshot. OAuth completes before it returns.
                $login = $this->storedSession($id);
                $loginRequest = Request::create('/auth/google');
                $loginRequest->setLaravelSession($login);
                Socialite::shouldReceive('driver')->with('google')->andReturn(new GoogleProvider($loginRequest, 'test', 'test', 'https://valzeria.com/auth/google/callback'));
                app(AuthController::class)->redirectToGoogle($loginRequest);
                $state = $login->get('state');
                $startedAt = $login->get('auth.google_started_at');
                $login->save();

                return (new TopPageAnalyticsService)->recordEvent($analyticsRequest, $visit, $event, $metadata);
            }
        );

        $response = $this->withCookie(config('session.cookie'), $id)->post('/top-analytics/event', [
            '_token' => $initial->token(), 'event_name' => 'google_start_click',
        ]);
        $response->assertNoContent()->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
        $persisted = $this->storedSession($id);
        $this->assertNotEmpty($state);
        $this->assertSame($state, $persisted->get('state'));
        $this->assertSame($startedAt, $persisted->get('auth.google_started_at'));
        $this->assertDatabaseHas('top_page_events', ['event_name' => 'google_start_click', 'user_id' => $user->id]);
    }

    public function test_delayed_analytics_does_not_recreate_a_session_destroyed_by_login(): void
    {
        $initial = $this->storedSession();
        $initial->save();
        $id = $initial->getId();
        $newId = null;
        $this->mock(TopPageAnalyticsService::class)->shouldReceive('recordEvent')->once()->andReturnUsing(
            function () use ($id, &$newId) {
                $login = $this->storedSession($id);
                $login->regenerate(true);
                $login->put('state', 'new-login-state');
                $login->save();
                $newId = $login->getId();
                return null;
            }
        );
        $this->withCookie(config('session.cookie'), $id)->post('/top-analytics/event', [
            '_token' => $initial->token(), 'event_name' => 'page_dwell', 'duration_seconds' => 1,
        ])->assertNoContent()->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->assertSame('new-login-state', $this->storedSession($newId)->get('state'));
    }

    public function test_google_start_still_persists_session_and_issues_cookies(): void
    {
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(
            fn () => new GoogleProvider(request(), 'test', 'test', 'https://valzeria.com/auth/google/callback')
        );
        $this->get('/auth/google')->assertRedirect()
            ->assertCookie(config('session.cookie'))->assertCookie('XSRF-TOKEN');
        $persisted = $this->storedSession(session()->getId());
        $this->assertNotEmpty($persisted->get('state'));
        $this->assertNotNull($persisted->get('auth.google_started_at'));
    }

    public function test_invalid_csrf_is_rejected_and_analytics_does_not_create_a_session(): void
    {
        $this->postJson('/top-analytics/event', ['event_name' => 'page_dwell'])->assertStatus(419);
        $this->assertDatabaseCount('top_page_events', 0);
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_validation_failure_preserves_flash_and_session_payload(): void
    {
        $initial = $this->storedSession();
        $initial->flash('error', 'ログインの確認に失敗しました。');
        $initial->save();
        $id = $initial->getId();
        $payload = DB::table('sessions')->where('id', $id)->value('payload');
        $this->withCookie(config('session.cookie'), $id)->post('/top-analytics/event', [
            '_token' => $initial->token(), 'event_name' => str_repeat('a', 81),
        ])->assertStatus(302)->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
        $this->assertSame($payload, DB::table('sessions')->where('id', $id)->value('payload'));
        $this->assertDatabaseCount('top_page_events', 0);
    }
}
