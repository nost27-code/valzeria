<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\GoldTransaction;
use App\Models\User;
use App\Services\InnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InnPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_beginner_period_lodging_spends_ten_gold_and_records_the_pricing_rule(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        $character = $this->character('2026-09-04 12:00:01');

        $result = app(InnService::class)->rest($character);

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['paid']);
        $this->assertSame(490, (int) $character->fresh()->money);

        $transaction = GoldTransaction::query()->where('character_id', $character->id)->sole();
        $this->assertSame(-10, (int) $transaction->amount);
        $this->assertSame('beginner_period', $transaction->metadata['pricing_rule']);
    }

    public function test_regular_lodging_spends_the_level_based_fee_at_the_ten_day_boundary(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        $character = $this->character('2026-09-04 12:00:00');

        $result = app(InnService::class)->rest($character);

        $this->assertTrue($result['success']);
        $this->assertSame(210, $result['paid']);
        $this->assertSame(290, (int) $character->fresh()->money);

        $transaction = GoldTransaction::query()->where('character_id', $character->id)->sole();
        $this->assertSame(-210, (int) $transaction->amount);
        $this->assertSame('regular', $transaction->metadata['pricing_rule']);
    }

    private function character(string $createdAt): Character
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '宿代確認者',
            'level' => 21,
            'hp_base' => 100,
            'mp_base' => 100,
            'current_hp' => 20,
            'current_mp' => 20,
            'money' => 500,
            'bank_gold' => 0,
            'explore_stamina' => 0,
        ]);
        $character->forceFill(['created_at' => Carbon::parse($createdAt)])->save();

        return $character;
    }
}
