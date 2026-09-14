<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\InnService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InnServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_level_twenty_or_lower_keeps_the_ten_gold_fee_after_the_beginner_period(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $character = $this->character(20, '2026-08-01 12:00:00');

        $this->assertSame(10, app(InnService::class)->fee($character));
    }

    public function test_high_level_character_pays_ten_gold_during_the_first_ten_days(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $character = $this->character(255, '2026-09-04 12:00:01');

        $this->assertSame(10, app(InnService::class)->fee($character));
    }

    public function test_regular_level_fee_starts_exactly_ten_days_after_character_creation(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $character = $this->character(21, '2026-09-04 12:00:00');

        $this->assertSame(210, app(InnService::class)->fee($character));
    }

    public function test_missing_creation_time_falls_back_to_the_level_rule(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $character = $this->character(21, null);

        $this->assertSame(210, app(InnService::class)->fee($character));
    }

    private function character(int $level, ?string $createdAt): Character
    {
        $character = new Character(['level' => $level]);
        $character->created_at = $createdAt !== null ? Carbon::parse($createdAt) : null;

        return $character;
    }
}
