<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserInvestigationManager;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterNotification;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserInvestigationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_investigation_shows_notifications_without_marking_them_as_read(): void
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '通知確認冒険者',
            'explore_stamina' => 0,
        ]);
        $notification = CharacterNotification::query()->create([
            'character_id' => $character->id,
            'type' => 'admin_gold_grant',
            'title' => '運営からのお知らせ',
            'body' => '補填内容を確認してください。',
        ]);
        $consumable = Item::query()->create([
            'name' => '守りの香',
            'type' => 'consumable',
            'is_active' => true,
        ]);
        $weapon = Item::query()->create([
            'name' => '調査用の剣',
            'type' => 'weapon',
            'weapon_rank' => 'G',
            'is_active' => true,
        ]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $consumable->id]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $consumable->id]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $weapon->id]);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $user->id)
            ->call('searchUser')
            ->assertSeeHtml('data-admin-investigation-accordion')
            ->assertSee('調査サマリー')
            ->assertSee('所持・育成')
            ->assertSee('進行・行動')
            ->assertSee('運営・監査')
            ->assertSee('技術調査')
            ->assertSee('装備倉庫')
            ->assertSee('調査用の剣')
            ->assertSee('所持アイテム')
            ->assertSee('守りの香')
            ->assertSee('2個 / 1種')
            ->assertSee('通知（閲覧のみ）')
            ->assertSee('運営からのお知らせ')
            ->assertSee('補填内容を確認してください。')
            ->assertSee('未読');

        $this->assertNull($notification->fresh()->read_at);
    }
}
