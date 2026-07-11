<?php

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendPortalOnlineCount extends Command
{
    protected $signature = 'portal:send-online-count {--dry-run : Count online characters without sending the portal request}';

    protected $description = 'Send the current online character count to the external game portal.';

    public function handle(): int
    {
        $enabled = (bool) config('services.pochi_game_portal.enabled', false);
        $endpoint = (string) config('services.pochi_game_portal.endpoint', '');
        $gameKey = (string) config('services.pochi_game_portal.game_key', '');
        $apiKey = (string) config('services.pochi_game_portal.api_key', '');
        $windowMinutes = max(1, (int) config('services.pochi_game_portal.online_window_minutes', 5));

        $onlineCount = Character::visibleToPublic()
            ->where('last_seen_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($this->option('dry-run')) {
            $this->info("online_count={$onlineCount}");
            return self::SUCCESS;
        }

        if (!$enabled) {
            $this->line('Portal online count sending is disabled.');
            return self::SUCCESS;
        }

        if ($endpoint === '' || $gameKey === '' || $apiKey === '') {
            $this->error('Portal online count settings are incomplete.');
            return self::FAILURE;
        }

        try {
            $response = Http::timeout(10)->get($endpoint, [
                'game_key' => $gameKey,
                'api_key' => $apiKey,
                'online_count' => $onlineCount,
            ]);

            if (!$response->successful()) {
                Log::warning('Portal online count request failed.', [
                    'status' => $response->status(),
                    'online_count' => $onlineCount,
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                $this->error('Portal online count request failed: HTTP ' . $response->status());
                return self::FAILURE;
            }

            $this->info("Portal online count sent: {$onlineCount}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::warning('Portal online count request errored.', [
                'online_count' => $onlineCount,
                'message' => $e->getMessage(),
            ]);

            $this->error('Portal online count request errored: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
