<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use App\Models\User;
use App\Services\HomeActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeActionNationPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recommends_joining_a_nation_to_an_unaffiliated_character(): void
    {
        config()->set('features.nation_community_enabled', true);
        $character = $this->createCharacter();

        $action = $this->nationActionFor($character);

        $this->assertNotNull($action);
        $this->assertSame('nation_join_recommended', $action['key']);
        $this->assertSame('国家に加入してみよう', $action['title']);
        $this->assertSame('nation', $action['tab']);
        $this->assertSame(87, $action['priority']);
    }

    public function test_it_switches_to_an_application_status_prompt_while_pending(): void
    {
        config()->set('features.nation_community_enabled', true);
        $character = $this->createCharacter();
        $nation = $this->createNation();

        NationJoinApplication::query()->create([
            'nation_id' => $nation->id,
            'character_id' => $character->id,
            'status' => NationJoinApplication::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $action = $this->nationActionFor($character);

        $this->assertNotNull($action);
        $this->assertSame('nation_join_application_pending', $action['key']);
        $this->assertSame('国家への加入申請を確認しよう', $action['title']);
    }

    public function test_it_hides_the_prompt_for_members_and_when_the_feature_is_disabled(): void
    {
        config()->set('features.nation_community_enabled', true);
        $character = $this->createCharacter();
        $nation = $this->createNation();

        NationMembership::query()->create([
            'nation_id' => $nation->id,
            'character_id' => $character->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $this->assertNull($this->nationActionFor($character));

        $character = $this->createCharacter();
        config()->set('features.nation_community_enabled', false);

        $this->assertNull($this->nationActionFor($character));
    }

    private function nationActionFor(Character $character): ?array
    {
        return collect(app(HomeActionService::class)->getActions(
            $character,
            5,
            ['max_hp' => 100, 'max_mp' => 50]
        ))->first(fn (array $action): bool => str_starts_with($action['key'], 'nation_join'));
    }

    private function createCharacter(): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '国家案内テスト',
            'level' => 1,
            'hp_base' => 100,
            'mp_base' => 50,
            'current_hp' => 100,
            'current_mp' => 50,
        ]);
    }

    private function createNation(): Nation
    {
        return Nation::query()->create([
            'name' => '国家案内テスト国',
            'nation_type' => 'kingdom',
            'recruitment_enabled' => true,
            'status' => Nation::STATUS_ACTIVE,
            'founded_at' => now(),
        ]);
    }
}
