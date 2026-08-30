<?php

namespace Tests\Feature;

use App\Livewire\TitleList;
use App\Models\Character;
use App\Models\Title;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TitleListRecentBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_titles_granted_within_three_days_show_new_badge_and_older_titles_do_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-30 12:00:00', 'Asia/Tokyo'));

        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '称号確認者',
        ]);
        $recentTitle = $this->createTitle('昨日の称号', 9001);
        $boundaryTitle = $this->createTitle('三日前の称号', 9002);
        $expiredTitle = $this->createTitle('期限外の称号', 9003);
        $futureTitle = $this->createTitle('未来日時の称号', 9004);
        $unownedTitle = $this->createTitle('未所持の称号', 9005);

        $this->grantTitle($character, $recentTitle, now()->subDay(), true);
        $this->grantTitle($character, $boundaryTitle, now()->subDays(3));
        $this->grantTitle($character, $expiredTitle, now()->subDays(3)->subSecond());
        $this->grantTitle($character, $futureTitle, now()->addSecond());

        $this->actingAs($user);

        Livewire::test(TitleList::class)
            ->assertSet('characterTitles', function (array $titles) use ($recentTitle, $boundaryTitle, $expiredTitle, $futureTitle, $unownedTitle): bool {
                return $titles[$recentTitle->id]['is_new'] === true
                    && $titles[$boundaryTitle->id]['is_new'] === true
                    && $titles[$expiredTitle->id]['is_new'] === false
                    && $titles[$futureTitle->id]['is_new'] === false
                    && ! isset($titles[$unownedTitle->id]);
            })
            ->assertSeeHtml('data-title-new-badge="'.$recentTitle->id.'"')
            ->assertSeeHtml('data-title-new-badge="'.$boundaryTitle->id.'"')
            ->assertDontSeeHtml('data-title-new-badge="'.$expiredTitle->id.'"')
            ->assertDontSeeHtml('data-title-new-badge="'.$futureTitle->id.'"')
            ->assertDontSeeHtml('data-title-new-badge="'.$unownedTitle->id.'"')
            ->assertSee('NEW')
            ->assertSee('装備中');
    }

    private function createTitle(string $name, int $displayOrder): Title
    {
        return Title::query()->create([
            'category' => 'achievement',
            'rarity' => 'rare',
            'name' => $name,
            'description' => $name.'の説明',
            'hint' => $name.'のヒント',
            'unlock_type' => 'test',
            'display_order' => $displayOrder,
            'is_hidden' => false,
        ]);
    }

    private function grantTitle(
        Character $character,
        Title $title,
        Carbon $grantedAt,
        bool $isEquipped = false,
    ): void {
        DB::table('character_titles')->insert([
            'character_id' => $character->id,
            'title_id' => $title->id,
            'is_equipped' => $isEquipped,
            'created_at' => $grantedAt,
            'updated_at' => $grantedAt,
        ]);
    }
}
