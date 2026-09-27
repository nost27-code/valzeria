<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\CharacterBackgroundOperation;
use App\Services\CharacterPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterBackgroundOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_uses_current_database_state_and_does_not_overwrite_gameplay(): void
    {
        $actor = Character::create(['user_id' => User::factory()->create()->id, 'name' => '在席試験', 'money' => 100, 'last_seen_at' => now()->subMinutes(2)]);
        $stale = $actor->fresh();
        $actor->update(['money' => 200]);
        $updatedAt = $actor->updated_at;
        app(CharacterPresenceService::class)->touch($stale);
        $this->assertSame(200, (int) $actor->fresh()->money);
        $this->assertTrue($actor->fresh()->updated_at->equalTo($updatedAt));
        $seenAt = $actor->fresh()->last_seen_at;
        $this->travel(10)->seconds();
        app(CharacterPresenceService::class)->touch($stale);
        $this->assertTrue($actor->fresh()->last_seen_at->equalTo($seenAt));
        $this->assertTrue($stale->timestamps);
    }

    public function test_callback_failure_rolls_back_and_is_not_silently_skipped(): void
    {
        $actor = Character::create(['user_id' => User::factory()->create()->id, 'name' => '巻戻し試験', 'money' => 100]);
        try {
            app(CharacterBackgroundOperation::class)->run($actor->id, function ($locked): void {
                $locked->update(['money' => 999]);
                throw new \RuntimeException('unexpected failure');
            });
            $this->fail('Unexpected errors must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('unexpected failure', $exception->getMessage());
        }
        $this->assertSame(100, (int) $actor->fresh()->money);
    }

    public function test_missing_character_never_runs_maintenance(): void
    {
        $this->assertFalse(app(CharacterBackgroundOperation::class)->run(999999, fn () => $this->fail('Missing character callback')));
    }
}
