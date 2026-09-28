<?php

namespace Tests\Feature;

use App\Models\GameSetting;
use App\Services\GameSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleLoginFailureFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        GameSetting::updateOrCreate(
            ['setting_key' => 'auth.registration_open'],
            ['value' => '1', 'value_type' => 'bool']
        );
        app(GameSettingService::class)->flush();
    }

    public function test_top_page_displays_google_login_error(): void
    {
        $message = 'Googleログインを完了できませんでした。';

        $this->withSession(['error' => $message])
            ->get(route('top'))
            ->assertOk()
            ->assertSee($message);
    }

    public function test_callback_without_code_returns_visible_error_before_contacting_google(): void
    {
        Socialite::shouldReceive('driver')->never();
        Log::shouldReceive('warning')
            ->once()
            ->with('Google login callback rejected.', \Mockery::on(
                fn (array $context): bool => $context['reason'] === 'missing_code'
                    && $context['has_code'] === false
                    && $context['has_state'] === true
            ));

        $response = $this->withSession([
            'state' => 'valid-state',
            'auth.google_started_at' => now()->timestamp,
        ])->get(route('auth.google.callback', ['state' => 'valid-state']));

        $response
            ->assertRedirect(route('top'))
            ->assertSessionHas('error', 'Googleからログイン情報を受け取れませんでした。トップページからもう一度お試しください。')
            ->assertSessionMissing('state')
            ->assertSessionMissing('auth.google_started_at')
            ->assertSessionMissing('auth.google_link_user_id');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Googleからログイン情報を受け取れませんでした。');
    }

    public function test_provider_cancellation_returns_specific_error(): void
    {
        Socialite::shouldReceive('driver')->never();
        Log::shouldReceive('warning')
            ->once()
            ->with('Google login callback rejected.', \Mockery::on(
                fn (array $context): bool => $context['reason'] === 'provider_error'
                    && $context['has_code'] === false
                    && $context['has_state'] === true
            ));

        $this->withSession([
            'state' => 'valid-state',
            'auth.google_started_at' => now()->timestamp,
        ])->get(route('auth.google.callback', [
            'error' => 'access_denied',
            'state' => 'valid-state',
        ]))
            ->assertRedirect(route('top'))
            ->assertSessionHas('error', 'Googleログインがキャンセルされました。ログインする場合は、もう一度「Googleでログイン」を押してください。');
    }
}
