<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\SecurityLoginRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityLoginRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_expired_records_are_removed_and_each_run_is_bounded(): void
    {
        $user = User::factory()->create();
        $rows = [];
        for ($i = 0; $i < 202; $i++) {
            $rows[] = $this->row($user->id, $i, now()->subDays(91)->toDateTimeString());
        }
        DB::table('security_login_observations')->insert($rows);
        $active = DB::table('security_login_observations')->insertGetId($this->row($user->id, 203, now()->toDateTimeString()));
        $service = app(SecurityLoginRetentionService::class);
        $this->assertSame(['pruned' => 200, 'deferred' => 0], $service->prune());
        $this->assertSame(['pruned' => 2, 'deferred' => 0], $service->prune());
        $this->assertSame(['pruned' => 0, 'deferred' => 0], $service->prune());
        $this->assertSame([$active], DB::table('security_login_observations')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_refreshed_candidate_is_preserved_and_unexpected_sql_errors_are_not_hidden(): void
    {
        $user = User::factory()->create();
        $id = DB::table('security_login_observations')->insertGetId($this->row($user->id, 1, now()->subDays(91)->toDateTimeString()));
        $cutoff = now()->subDays(90);
        DB::table('security_login_observations')->where('id', $id)->update(['last_observed_at' => now()]);
        $service = app(SecurityLoginRetentionService::class);
        $this->assertSame(0, (new \ReflectionMethod($service, 'pruneCandidate'))->invoke($service, $id, $cutoff));
        $this->assertSame(1, DB::table('security_login_observations')->where('id', $id)->count());
        DB::shouldReceive('table')->once()->with('security_login_observations')
            ->andThrow(new \Illuminate\Database\QueryException('sqlite', 'fixture', [], new \RuntimeException('unexpected SQL failure')));
        $this->expectException(\Illuminate\Database\QueryException::class);
        $service->prune();
    }

    private function row(int $userId, int $number, string $time): array
    {
        return ['user_id' => $userId, 'ip_hash' => hash('sha256', (string) $number), 'masked_ip' => 'fixture',
            'observed_date' => substr($time, 0, 10), 'first_observed_at' => $time, 'last_observed_at' => $time,
            'observation_count' => 1, 'created_at' => now(), 'updated_at' => now()];
    }
}
