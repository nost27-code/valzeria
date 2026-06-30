<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\ExternalFeedItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncNoteRssUpdates extends Command
{
    protected $signature = 'note:rss-sync {--dry-run : Fetch and parse the latest note RSS item without creating notifications}';

    protected $description = 'Fetch the latest note RSS item and notify players when it is new.';

    public function handle(): int
    {
        $enabled = (bool) config('services.note_rss_notifications.enabled', true);
        $rssUrl = trim((string) config('services.note_rss_notifications.url', ''));

        if (!$this->option('dry-run') && !$enabled) {
            $this->line('note RSS notifications are disabled.');
            return self::SUCCESS;
        }

        if ($rssUrl === '') {
            $this->error('note RSS URL is not configured.');
            return self::FAILURE;
        }

        if (!$this->option('dry-run') && (!Schema::hasTable('external_feed_items') || !Schema::hasTable('character_notifications'))) {
            $this->error('Required notification tables are missing.');
            return self::FAILURE;
        }

        $latest = $this->latestFeedItem($rssUrl);
        if ($latest === null) {
            $this->error('No note RSS item found.');
            return self::FAILURE;
        }

        $this->line('latest_title=' . $latest['title']);
        $this->line('latest_url=' . $latest['url']);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $guidHash = hash('sha256', $latest['guid']);
        $alreadyExists = ExternalFeedItem::query()
            ->where('source', 'note')
            ->where('guid_hash', $guidHash)
            ->exists();

        if ($alreadyExists) {
            $this->line('Latest note RSS item is already notified.');
            return self::SUCCESS;
        }

        $notificationCount = DB::transaction(function () use ($latest, $guidHash): int {
            $feedItem = ExternalFeedItem::create([
                'source' => 'note',
                'guid_hash' => $guidHash,
                'guid' => $latest['guid'],
                'title' => $latest['title'],
                'url' => $latest['url'],
                'published_at' => $latest['published_at'],
                'notified_at' => now(),
                'raw' => [
                    'description' => $latest['description'],
                ],
            ]);

            return $this->createNotifications($feedItem);
        });

        $this->info("note RSS notification created for {$notificationCount} characters.");
        return self::SUCCESS;
    }

    private function latestFeedItem(string $rssUrl): ?array
    {
        try {
            $response = Http::timeout(10)
                ->accept('application/rss+xml, application/xml, text/xml')
                ->get($rssUrl);
        } catch (\Throwable $e) {
            Log::warning('note RSS request errored.', [
                'url' => $rssUrl,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('note RSS request failed.', [
                'url' => $rssUrl,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        return $this->parseLatestItem($response->body());
    }

    private function parseLatestItem(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$feed || !isset($feed->channel->item)) {
            return null;
        }

        $items = [];
        foreach ($feed->channel->item as $item) {
            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            $guid = trim((string) $item->guid) ?: $link;

            if ($title === '' || $link === '' || $guid === '') {
                continue;
            }

            $publishedAt = $this->parseDate(trim((string) $item->pubDate));
            $items[] = [
                'guid' => $guid,
                'title' => mb_substr($title, 0, 255),
                'url' => mb_substr($link, 0, 255),
                'published_at' => $publishedAt,
                'description' => mb_substr(trim(strip_tags((string) $item->description)), 0, 500),
                'sort_at' => $publishedAt?->getTimestamp() ?? 0,
            ];
        }

        if ($items === []) {
            return null;
        }

        usort($items, fn (array $a, array $b): int => $b['sort_at'] <=> $a['sort_at']);

        unset($items[0]['sort_at']);
        return $items[0];
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function createNotifications(ExternalFeedItem $feedItem): int
    {
        $now = now();
        $columns = [
            'character_id',
            'type',
            'title',
            'body',
            'url',
            'data',
            'created_at',
            'updated_at',
        ];

        $hasCategory = Schema::hasColumn('character_notifications', 'category');
        $hasActionLabel = Schema::hasColumn('character_notifications', 'action_label');
        $hasPriority = Schema::hasColumn('character_notifications', 'priority');

        if ($hasCategory) {
            $columns[] = 'category';
        }
        if ($hasActionLabel) {
            $columns[] = 'action_label';
        }
        if ($hasPriority) {
            $columns[] = 'priority';
        }

        $count = 0;
        Character::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($characters) use ($feedItem, $now, $columns, $hasCategory, $hasActionLabel, $hasPriority, &$count) {
                $rows = [];

                foreach ($characters as $character) {
                    $row = [
                        'character_id' => $character->id,
                        'type' => 'note_rss_update',
                        'title' => 'noteを更新しました',
                        'body' => $feedItem->title,
                        'url' => $feedItem->url,
                        'data' => json_encode([
                            'external_feed_item_id' => $feedItem->id,
                            'source' => 'note',
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($hasCategory) {
                        $row['category'] = 'news';
                    }
                    if ($hasActionLabel) {
                        $row['action_label'] = 'noteを見る';
                    }
                    if ($hasPriority) {
                        $row['priority'] = 20;
                    }

                    $rows[] = array_replace(array_fill_keys($columns, null), $row);
                }

                if ($rows !== []) {
                    DB::table('character_notifications')->insert($rows);
                    $count += count($rows);
                }
            });

        return $count;
    }
}
