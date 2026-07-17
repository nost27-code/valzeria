<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SecurityLoginObservationService
{
    public function observe(User $user, ?string $ipAddress): void
    {
        $ip = $this->normalizeIp($ipAddress);
        if (! config('security_anomaly_detection.enabled', true)
            || $ip === null
            || (string) ($user->role ?? '') === 'admin'
            || ! Schema::hasTable('security_login_observations')) {
            return;
        }

        $now = now();
        $hash = hash_hmac('sha256', $ip, (string) config('app.key'));

        $key = [
            'user_id' => $user->id,
            'ip_hash' => $hash,
            'observed_date' => $now->toDateString(),
        ];
        DB::table('security_login_observations')->insertOrIgnore([
            ...$key,
            'masked_ip' => $this->maskIp($ip),
            'first_observed_at' => $now,
            'last_observed_at' => $now,
            'observation_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('security_login_observations')->where($key)->update([
            'last_observed_at' => $now,
            'observation_count' => DB::raw('observation_count + 1'),
            'updated_at' => $now,
        ]);
    }

    private function normalizeIp(?string $ipAddress): ?string
    {
        $ip = trim((string) $ipAddress);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : strtolower((string) inet_ntop($packed));
    }

    private function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'xxx';

            return implode('.', $parts);
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return 'masked';
        }

        $hex = unpack('H*', $packed)[1] ?? '';
        $groups = str_split($hex, 4);

        return implode(':', array_slice($groups, 0, 3)).':xxxx::*';
    }
}
