<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValmonRanchDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranch_scene_pages_the_eleventh_valmon_and_shows_the_actual_total(): void
    {
        [$user, $character] = $this->createCharacterWithValmons(11);

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('valmons.index'))
            ->assertOk()
            ->assertSeeText('仲間 11体')
            ->assertDontSee('/ 10体')
            ->assertSee('data-pasture-page-count="2"', false)
            ->assertSeeInOrder([
                'data-pasture-page="1"',
                'data-pasture-page="2"',
            ], false)
            ->assertSee('aria-label="前の10体を表示"', false)
            ->assertSee('aria-label="次の10体を表示"', false)
            ->assertSeeText('1 / 2');

        $html = (string) $response->getContent();

        $this->assertSame(10, substr_count($html, 'data-pasture-entry-page="1"'));
        $this->assertSame(1, substr_count($html, 'data-pasture-entry-page="2"'));
        $this->assertStringContainsString('alt="牧場ヴァルモン11"', $html);
    }

    public function test_ranch_scene_does_not_show_paging_controls_for_ten_valmons(): void
    {
        [$user, $character] = $this->createCharacterWithValmons(10);

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('valmons.index'))
            ->assertOk()
            ->assertSeeText('仲間 10体')
            ->assertDontSee('/ 10体')
            ->assertDontSee('data-pasture-pagination', false)
            ->assertSee('data-pasture-page="1"', false)
            ->assertDontSee('data-pasture-page="2"', false);

        $this->assertSame(
            10,
            substr_count((string) $response->getContent(), 'data-pasture-entry-page="1"')
        );
    }

    public function test_all_thirty_five_valmons_are_available_over_four_pages_and_partner_is_on_first_page(): void
    {
        [$user, $character] = $this->createCharacterWithValmons(35, 35);
        $response = $this->actingAs($user)->withSession(['current_character_id' => $character->id])
            ->get(route('valmons.index'))->assertOk()->assertSeeText('仲間 35体');
        $html = (string) $response->getContent();
        foreach ([1 => 10, 2 => 10, 3 => 10, 4 => 5] as $page => $count) {
            $this->assertSame($count, substr_count($html, 'data-pasture-entry-page="'.$page.'"'));
        }
        $this->assertStringContainsString('data-pasture-page-count="4"', $html);
        $this->assertStringNotContainsString('data-pasture-page="5"', $html);
        preg_match('~data-pasture-entry-page="1"[^>]*>.*?alt="([^"]+)"~s', $html, $first);
        $this->assertSame('牧場ヴァルモン35', $first[1]);
        preg_match_all('~data-pasture-entry-page="[1-4]"[^>]*>.*?alt="([^"]+)"~s', $html, $entries);
        $this->assertCount(35, array_unique($entries[1]));
    }

    private function createCharacterWithValmons(int $count, int $partnerNumber = 1): array
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '牧場表示テスト',
        ]);

        foreach (range(1, $count) as $number) {
            $master = ValmonMaster::create([
                'valmon_key' => 'ranch-display-' . $number,
                'name' => '牧場ヴァルモン' . $number,
                'image_path' => 'images/valmon/valmon' . str_pad((string) (($number - 1) % 21) + 1, 2, '0', STR_PAD_LEFT) . '.webp',
                'rarity' => 'normal',
                'is_active' => true,
                'sort_order' => $number,
            ]);

            PlayerValmon::create([
                'character_id' => $character->id,
                'valmon_master_id' => $master->id,
                'level' => 1,
                'is_partner' => $number === $partnerNumber,
                'obtained_at' => now(),
            ]);
        }

        return [$user, $character];
    }
}
