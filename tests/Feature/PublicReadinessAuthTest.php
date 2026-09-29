<?php

namespace Tests\Feature;

use App\Models\GameSetting;
use App\Models\User;
use App\Services\GameSettingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class PublicReadinessAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        GameSetting::updateOrCreate(['setting_key' => 'auth.registration_open'], ['value' => '1', 'value_type' => 'bool']);
        app(GameSettingService::class)->flush();
    }

    private function google(array $responses = []): MockHandler
    {
        $handler = new MockHandler($responses);
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(function () use ($handler) {
            return (new GoogleProvider(request(), 'test-client', 'test-secret', route('auth.google.callback')))
                ->setHttpClient(new Client(['handler' => HandlerStack::create($handler)]));
        });

        return $handler;
    }

    public function test_google_redirect_stores_state_and_clears_previous_link_intent(): void
    {
        $this->google();
        $response = $this->withSession(['auth.google_link_user_id' => 123])->get(route('auth.google'));
        $response->assertRedirect()->assertSessionMissing('auth.google_link_user_id')->assertSessionHas('state');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(session('state'), $query['state']);
    }

    public function test_google_callback_rejects_missing_wrong_and_expired_state_before_network(): void
    {
        $before = User::count();
        $handler = $this->google();
        foreach ([null, 'wrong'] as $state) {
            $this->withSession(['state' => 'valid', 'auth.google_started_at' => now()->timestamp])
                ->get(route('auth.google.callback', ['code' => 'code', 'state' => $state]))
                ->assertRedirect(route('top'))->assertSessionHas('error');
            $this->assertGuest();
        }
        $this->withSession(['state' => 'valid', 'auth.google_started_at' => now()->subMinutes(11)->timestamp])
            ->get(route('auth.google.callback', ['code' => 'code', 'state' => 'valid']))
            ->assertRedirect(route('top'))->assertSessionHas('error');
        $this->assertDatabaseCount('users', $before);
        $this->assertNull($handler->getLastRequest());
    }

    private function googleResponses(): array
    {
        return [new Response(200, [], json_encode(['access_token' => 'test-token', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['sub' => 'google-test', 'email' => 'google@example.com', 'name' => '試験者', 'email_verified' => true]))];
    }

    public function test_unrelated_callbacks_preserve_the_latest_login_until_it_succeeds(): void
    {
        $handler = $this->google(array_merge(...array_fill(0, 5, $this->googleResponses())));

        foreach (['old_code', 'old_cancel', 'old_missing_code', 'missing_state', 'array_state'] as $scenario) {
            $this->get(route('auth.google'))->assertRedirect();
            $oldState = session('state');
            $this->get(route('auth.google'))->assertRedirect();
            $state = session('state');
            $startedAt = session('auth.google_started_at');
            $this->assertNotSame($oldState, $state);
            $remainingResponses = $handler->count();
            $query = match ($scenario) {
                'old_code' => ['code' => 'old', 'state' => $oldState],
                'old_cancel' => ['error' => 'access_denied', 'state' => $oldState],
                'old_missing_code' => ['state' => $oldState],
                'missing_state' => ['code' => 'old'],
                'array_state' => ['code' => 'old', 'state' => ['invalid']],
            };

            $this->get(route('auth.google.callback', $query))
                ->assertRedirect(route('top'))
                ->assertSessionHas('error')
                ->assertSessionHas('state', $state)
                ->assertSessionHas('auth.google_started_at', $startedAt);
            $this->assertSame($remainingResponses, $handler->count());

            $this->get(route('auth.google.callback', ['code' => 'new', 'state' => $state]))
                ->assertRedirect(route('character.select'))
                ->assertSessionMissing('state')
                ->assertSessionMissing('auth.google_started_at');
            $this->assertAuthenticated();
            auth()->logout();
        }
    }

    public function test_old_cancellation_preserves_the_latest_guest_link(): void
    {
        $guest = User::create(['name' => 'ゲスト', 'email' => 'guest_'.Str::uuid().'@example.com']);
        $this->google($this->googleResponses());
        $this->actingAs($guest)->get(route('account.link.google'))->assertRedirect();
        $oldState = session('state');
        $this->get(route('account.link.google'))->assertRedirect();
        $state = session('state');

        $this->get(route('auth.google.callback', ['error' => 'access_denied', 'state' => $oldState]))
            ->assertRedirect(route('top'))
            ->assertSessionHas('state', $state)
            ->assertSessionHas('auth.google_link_user_id', $guest->id);

        $this->get(route('auth.google.callback', ['code' => 'new', 'state' => $state]))
            ->assertRedirect(route('home'))
            ->assertSessionMissing('auth.google_link_user_id');
        $this->assertAuthenticatedAs($guest);
        $this->assertSame('google-test', $guest->fresh()->google_id);
    }

    public function test_matching_state_still_rejects_invalid_start_times_without_network(): void
    {
        $handler = $this->google();
        foreach ([0, now()->addMinute()->timestamp, now()->subSeconds(601)->timestamp] as $startedAt) {
            $this->withSession(['state' => 'valid', 'auth.google_started_at' => $startedAt])
                ->get(route('auth.google.callback', ['code' => 'code', 'state' => 'valid']))
                ->assertRedirect(route('top'))
                ->assertSessionHas('error', 'ログインの有効期限が切れました。トップページからもう一度お試しください。')
                ->assertSessionMissing('state')
                ->assertSessionMissing('auth.google_started_at');
            $this->assertGuest();
        }
        $this->assertNull($handler->getLastRequest());
    }

    public function test_valid_google_callback_logs_in_once_and_replay_is_rejected(): void
    {
        $before = User::count();
        $this->google($this->googleResponses());
        $url = route('auth.google.callback', ['code' => 'code', 'state' => 'valid']);
        $this->withSession(['state' => 'valid', 'auth.google_started_at' => now()->timestamp])
            ->get($url)->assertRedirect(route('character.select'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['google_id' => 'google-test', 'email' => 'google@example.com']);
        $this->get($url)->assertRedirect(route('top'))->assertSessionHas('error');
        $this->assertDatabaseCount('users', $before + 1);
    }

    public function test_guest_link_preserves_the_guest_and_requires_matching_state(): void
    {
        $before = User::count();
        $guest = User::create(['name' => 'ゲスト', 'email' => 'guest_'.Str::uuid().'@example.com']);
        $this->google($this->googleResponses());
        $this->actingAs($guest)->get(route('account.link.google'))->assertRedirect()->assertSessionHas('state');
        $state = session('state');
        $this->get(route('auth.google.callback', ['code' => 'code', 'state' => $state]))->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($guest);
        $this->assertSame('google-test', $guest->fresh()->google_id);
        $this->assertDatabaseCount('users', $before + 1);
    }

    public function test_bad_login_attempts_are_limited_and_expire(): void
    {
        Cache::flush();
        foreach (['/login' => 10, '/admin/login' => 5] as $path => $limit) {
            for ($i = 0; $i < $limit; $i++) {
                $this->post($path, ['email' => 'wrong@example.com', 'password' => 'incorrect'])->assertStatus(302);
            }
            $response = $this->post($path, ['email' => 'wrong@example.com', 'password' => 'incorrect']);
            $response->assertStatus(429)->assertHeader('Retry-After')->assertSee('少し待ってからお試しください');
            $this->travel(61)->seconds();
            $this->post($path, ['email' => 'wrong@example.com', 'password' => 'incorrect'])->assertStatus(302);
        }
    }

    public function test_registration_and_guest_creation_share_an_ip_limit(): void
    {
        $before = User::count();
        Cache::flush();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', [])->assertStatus(302);
        }
        $this->post('/auth/guest-login')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', $before);
    }
}
