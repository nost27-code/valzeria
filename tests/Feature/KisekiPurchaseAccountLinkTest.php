<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KisekiPurchaseAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_start_a_kiseki_checkout(): void
    {
        $guest = User::query()->create([
            'name' => 'ゲスト冒険者',
            'email' => 'guest_9f8b9f4c-4847-4f42-852a-6b828e9860d0@example.com',
        ]);
        $this->characterFor($guest);

        $this->actingAs($guest)
            ->post(route('kiseki.checkout'), ['pack_key' => 'kiseki_mini'])
            ->assertRedirect(route('kiseki.shop'))
            ->assertSessionHas('error', '輝石の購入にはメール連携が必要です。Google連携を完了してからもう一度お試しください。');
    }

    public function test_guest_shop_shows_account_link_requirement_instead_of_purchase_button(): void
    {
        $guest = User::query()->create([
            'name' => 'ゲスト冒険者',
            'email' => 'guest_9f8b9f4c-4847-4f42-852a-6b828e9860d1@example.com',
        ]);
        $this->characterFor($guest);

        $this->actingAs($guest)
            ->get(route('kiseki.shop'))
            ->assertOk()
            ->assertSee('輝石の購入にはメール連携が必要です。')
            ->assertSee('Google連携をする')
            ->assertDontSee('>購入する<', false);
    }

    public function test_linked_account_can_see_kiseki_purchase_buttons(): void
    {
        $user = User::factory()->create(['email' => 'linked-adventurer@example.com']);
        $this->characterFor($user);

        $this->actingAs($user)
            ->get(route('kiseki.shop'))
            ->assertOk()
            ->assertSee('>購入する<', false)
            ->assertDontSee('輝石の購入にはメール連携が必要です。');
    }

    private function characterFor(User $user): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '購入確認冒険者',
            'explore_stamina' => 0,
        ]);
    }
}
