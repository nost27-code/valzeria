<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestGoogleLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_account_keeps_its_user_id_when_linked_to_google(): void
    {
        $guest = User::create([
            'name' => 'ゲスト冒険者',
            'email' => 'guest_9f8b9f4c-4847-4f42-852a-6b828e9860d0@example.com',
            'avatar_url' => 'https://example.com/guest.png',
        ]);

        $linked = app(AuthService::class)->linkGuestToGoogle(
            $guest,
            'google-user-123',
            'adventurer@example.com',
            'https://example.com/google.png',
        );

        $this->assertSame($guest->id, $linked->id);
        $this->assertDatabaseHas('users', [
            'id' => $guest->id,
            'google_id' => 'google-user-123',
            'email' => 'adventurer@example.com',
        ]);
        $this->assertFalse(app(AuthService::class)->isGuestUser($linked));
    }

    public function test_guest_cannot_link_a_google_account_already_used_by_another_user(): void
    {
        $guest = User::create([
            'name' => 'ゲスト冒険者',
            'email' => 'guest_9f8b9f4c-4847-4f42-852a-6b828e9860d1@example.com',
        ]);
        User::factory()->create([
            'google_id' => 'google-user-123',
            'email' => 'adventurer@example.com',
        ]);

        $this->expectException(\LogicException::class);
        app(AuthService::class)->linkGuestToGoogle(
            $guest,
            'google-user-123',
            'adventurer@example.com',
        );
    }
}
