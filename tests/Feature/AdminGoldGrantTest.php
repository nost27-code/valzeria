<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlayerControlManager;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\GoldTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminGoldGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_gold_to_an_individual_character_with_audit_and_notification(): void
    {
        $admin = User::factory()->create(['name' => '運営担当']);
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'money' => 100,
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('goldGrantAmount', 2500)
            ->set('goldGrantReason', '不具合のお詫び')
            ->call('grantGold')
            ->assertHasNoErrors()
            ->assertSet('goldGrantAmount', 1)
            ->assertSet('goldGrantReason', '');

        $this->assertSame(2600, (int) $recipient->fresh()->money);

        $transaction = GoldTransaction::query()->sole();
        $this->assertSame('admin_grant', $transaction->type);
        $this->assertSame(2500, $transaction->amount);
        $this->assertSame(2600, $transaction->balance_after);
        $this->assertSame('admin_grant', $transaction->source_type);
        $this->assertSame($admin->id, $transaction->source_id);
        $this->assertSame('不具合のお詫び', data_get($transaction->metadata, 'reason'));
        $this->assertSame('運営担当', data_get($transaction->metadata, 'admin_user_name'));

        $notification = CharacterNotification::query()
            ->where('character_id', $recipient->id)
            ->where('type', 'admin_gold_grant')
            ->sole();
        $this->assertSame('admin_gold_grant', $notification->type);
        $this->assertStringContainsString('2,500 Gold', (string) $notification->body);
    }

    public function test_gold_send_requires_a_reason_and_does_not_change_balance(): void
    {
        $admin = User::factory()->create();
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'money' => 100,
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('goldGrantAmount', 2500)
            ->set('goldGrantReason', '')
            ->call('grantGold')
            ->assertHasErrors(['goldGrantReason' => 'required']);

        $this->assertSame(100, (int) $recipient->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 0);
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $recipient->id,
            'type' => 'admin_gold_grant',
        ]);
    }
}
