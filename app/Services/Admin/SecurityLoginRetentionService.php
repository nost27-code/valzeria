<?php

namespace App\Services\Admin;

use App\Services\SecurityLoginRetentionBusyException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SecurityLoginRetentionService
{
    /** @return array{pruned:int,deferred:int} */
    public function prune(): array
    {
        $cutoff = now()->subDays((int) config('security_anomaly_detection.retention_days', 90));
        $result = ['pruned' => 0, 'deferred' => 0];
        // A range DELETE can lock the first active observation even with no expired rows.
        $ids = DB::table('security_login_observations')->where('last_observed_at', '<', $cutoff)
            ->orderBy('id')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            try {
                $result['pruned'] += $this->pruneCandidate((int) $id, $cutoff);
            } catch (SecurityLoginRetentionBusyException) {
                // Keep the record for the next run; actual anomaly detection continues.
                $result['deferred']++;
            }
        }
        return $result;
    }

    private function pruneCandidate(int $id, Carbon $cutoff): int
    {
        return DB::transaction(function () use ($id, $cutoff): int {
            try {
                $query = DB::table('security_login_observations')->where('id', $id);
                $record = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                    ? $query->lock('for update nowait')->first()
                    : $query->lockForUpdate()->first();
            } catch (QueryException $exception) {
                // Only an initial NOWAIT rejection is deferrable. Delete failures propagate.
                if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 3572], true)) {
                    throw new SecurityLoginRetentionBusyException(previous: $exception);
                }
                throw $exception;
            }
            if (! $record || Carbon::parse($record->last_observed_at)->gte($cutoff)) {
                return 0;
            }
            return DB::table('security_login_observations')->where('id', $id)->delete();
        });
    }
}
