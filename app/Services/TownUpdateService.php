<?php

namespace App\Services;

use App\Models\TopUpdate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class TownUpdateService
{
    private const PUBLIC_CACHE_KEY = 'town_updates:published';

    private const AUTO_IMPORT_LIMIT = 50;

    /**
     * 管理用更新サマリの新しいプレイヤー向け項目を、非公開の編集用下書きとして取り込む。
     */
    public function syncDraftsFromAdminSummaries(): int
    {
        if (! $this->supportsPublicationFields()) {
            return 0;
        }

        $created = 0;
        $summaries = collect(config('admin_update_summaries', []))
            ->filter(fn ($summary): bool => is_array($summary))
            ->filter(fn (array $summary): bool => in_array(
                (string) ($summary['category'] ?? ''),
                ['added', 'changed', 'fixed', 'balance'],
                true
            ))
            ->filter(fn (array $summary): bool => $this->hasImportableFields($summary))
            ->take(self::AUTO_IMPORT_LIMIT)
            ->values();

        foreach ($summaries as $index => $summary) {
            $update = TopUpdate::firstOrCreate(
                ['source_key' => (string) $summary['id']],
                [
                    'published_on' => (string) $summary['date'],
                    'body' => $this->headlineFor($summary),
                    'detail' => $this->detailFor($summary),
                    'source_category' => (string) $summary['category'],
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => false,
                    'is_dismissed' => false,
                ]
            );

            if ($update->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * 街ヘッダと更新履歴モーダルへ出す公開済み更新。
     */
    public function published(int $limit = 20): Collection
    {
        if (! Schema::hasTable('top_updates')) {
            return collect();
        }

        return Cache::remember(self::PUBLIC_CACHE_KEY, now()->addMinute(), function (): Collection {
            $query = TopUpdate::query()
                ->where('is_active', true)
                ->whereDate('published_on', '<=', today('Asia/Tokyo'))
                ->orderByDesc('published_on')
                ->orderBy('sort_order')
                ->orderByDesc('id');

            if (Schema::hasColumn('top_updates', 'is_dismissed')) {
                $query->where('is_dismissed', false);
            }

            return $query->limit(20)->get();
        })->take(max(1, $limit))->values();
    }

    public function forgetPublishedCache(): void
    {
        Cache::forget(self::PUBLIC_CACHE_KEY);
    }

    private function supportsPublicationFields(): bool
    {
        return Schema::hasTable('top_updates')
            && Schema::hasColumns('top_updates', [
                'detail',
                'source_key',
                'source_category',
                'is_dismissed',
            ]);
    }

    private function hasImportableFields(array $summary): bool
    {
        return trim((string) ($summary['id'] ?? '')) !== ''
            && trim((string) ($summary['date'] ?? '')) !== ''
            && trim((string) ($summary['title'] ?? '')) !== '';
    }

    private function headlineFor(array $summary): string
    {
        $detail = trim((string) ($summary['detail'] ?? ''));
        if ($detail !== '' && preg_match('/^(.+?。)/u', $detail, $matches) === 1) {
            $firstSentence = trim($matches[1]);
            if (mb_strlen($firstSentence) <= 255) {
                return $firstSentence;
            }
        }

        return (string) $summary['title'];
    }

    private function detailFor(array $summary): ?string
    {
        $detail = trim((string) ($summary['detail'] ?? ''));
        $headline = $this->headlineFor($summary);
        if ($detail !== '' && str_starts_with($detail, $headline)) {
            $detail = trim(mb_substr($detail, mb_strlen($headline)));
        }

        return $detail !== '' ? $detail : null;
    }
}
