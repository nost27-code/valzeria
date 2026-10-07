<?php

namespace App\Support;

class DatabaseConnectionCooldown
{
    public const SECONDS = 3;

    public function __construct(private ?string $filePath = null) {}

    public function remainingSeconds(): int
    {
        $expiresAt = @file_get_contents($this->path());
        if (! is_string($expiresAt) || ! ctype_digit(trim($expiresAt))) {
            return 0;
        }

        $remaining = (int) trim($expiresAt) - now()->getTimestamp();

        return $remaining > 0 && $remaining <= self::SECONDS ? $remaining : 0;
    }

    public function recordConnectionLimit(): void
    {
        // The configured cache/session stores may use the exhausted database.
        // Failure to write this optional marker must never mask the original error.
        @file_put_contents($this->path(), (string) (now()->getTimestamp() + self::SECONDS), LOCK_EX);
    }

    private function path(): string
    {
        if ($this->filePath !== null) {
            return $this->filePath;
        }

        $name = (string) config('database.default');
        $connection = config('database.connections.'.$name, []);
        $key = hash('sha256', json_encode([
            $name,
            $connection['host'] ?? null,
            $connection['port'] ?? null,
            $connection['database'] ?? null,
            $connection['username'] ?? null,
        ]));

        return storage_path('framework/cache/database-connection-cooldown-'.$key);
    }
}
