<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountInformationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_method_status_distinguishes_google_email_both_and_guest_accounts(): void
    {
        $service = app(AuthService::class);

        $google = $this->userWithRawLoginAttributes('google-user', null, 'google@example.test');
        $email = $this->userWithRawLoginAttributes(null, 'hashed-password', 'email@example.test');
        $both = $this->userWithRawLoginAttributes('both-user', 'hashed-password', 'both@example.test');
        $guest = $this->userWithRawLoginAttributes(
            null,
            null,
            'guest_00000000-0000-4000-8000-000000000000@example.com'
        );

        $this->assertSame([
            'google_linked' => true,
            'email_registered' => false,
            'is_guest' => false,
            'has_login_method' => true,
            'summary' => 'Google連携',
        ], $service->loginMethodStatus($google));
        $this->assertSame('メールアドレス登録', $service->loginMethodStatus($email)['summary']);
        $this->assertSame('Google・メールアドレス', $service->loginMethodStatus($both)['summary']);
        $this->assertSame([
            'google_linked' => false,
            'email_registered' => false,
            'is_guest' => true,
            'has_login_method' => false,
            'summary' => 'ゲストプレイ',
        ], $service->loginMethodStatus($guest));
    }

    public function test_settings_information_card_opens_google_account_status_modal(): void
    {
        $user = User::factory()->create([
            'google_id' => 'google-account-information-test',
            'password' => null,
        ]);
        $this->createCharacter($user, 'Google確認者');

        $this->actingAs($user);

        Livewire::test(MainScreen::class, ['fixedLocation' => 'settings'])
            ->assertSee('情報確認')
            ->assertSee('現在: Google連携')
            ->call('openAccountInfoModal')
            ->assertSet('isAccountInfoModalOpen', true)
            ->assertSet('accountInformation.google_linked', true)
            ->assertSet('accountInformation.email_registered', false)
            ->assertSee('Googleアカウントからログインできます。')
            ->assertSee('メールアドレスとパスワードでのログインは登録されていません。')
            ->call('closeAccountInfoModal')
            ->assertSet('isAccountInfoModalOpen', false)
            ->assertSet('accountInformation', []);
    }

    public function test_guest_account_status_modal_warns_before_logout_and_links_to_google(): void
    {
        $user = User::factory()->create([
            'google_id' => null,
            'email' => 'guest_10000000-0000-4000-8000-000000000000@example.com',
            'password' => null,
        ]);
        $this->createCharacter($user, 'ゲスト確認者');

        $this->actingAs($user);

        Livewire::test(MainScreen::class, ['fixedLocation' => 'settings'])
            ->assertSee('現在: ゲストプレイ')
            ->call('openAccountInfoModal')
            ->assertSet('accountInformation.is_guest', true)
            ->assertSee('現在はゲストプレイです')
            ->assertSee('Google連携してデータを引き継ぐ')
            ->assertSee(route('account.link.google'), false);
    }

    private function userWithRawLoginAttributes(?string $googleId, ?string $password, string $email): User
    {
        $user = new User;
        $user->setRawAttributes([
            'google_id' => $googleId,
            'password' => $password,
            'email' => $email,
        ]);

        return $user;
    }

    private function createCharacter(User $user, string $name): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
