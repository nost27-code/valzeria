<?php

namespace App\Services;

use App\Models\GameSetting;
use App\Models\TopUpdate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class TownUpdateService
{
    private const PUBLIC_CACHE_KEY = 'town_updates:published';

    private const AUTO_IMPORT_LIMIT = 50;

    private const DELETED_SOURCE_SETTING_PREFIX = 'town_updates.deleted.';

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

        if ($this->supportsDeletionRegistry()) {
            $this->deleteLegacyDismissedCandidates();
            $this->pruneDeletedSourceKeys();

            $deletedSettingKeys = GameSetting::query()
                ->whereIn('setting_key', $summaries
                    ->map(fn (array $summary): string => $this->deletedSourceSettingKey((string) $summary['id']))
                    ->all())
                ->pluck('setting_key')
                ->all();

            $summaries = $summaries
                ->reject(fn (array $summary): bool => in_array(
                    $this->deletedSourceSettingKey((string) $summary['id']),
                    $deletedSettingKeys,
                    true
                ))
                ->values();
        }

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
     * 更新情報を削除する。自動生成候補は削除済みキーを記録し、再生成を防ぐ。
     */
    public function deleteUpdate(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $update = TopUpdate::query()->lockForUpdate()->findOrFail($id);
            $sourceKey = trim((string) ($update->source_key ?? ''));

            if ($sourceKey !== '') {
                if (! $this->supportsDeletionRegistry()) {
                    throw new LogicException('削除済み更新候補の記録先を利用できません。');
                }

                $this->rememberDeletedSourceKey($sourceKey);
            }

            $update->delete();
        });

        $this->pruneDeletedSourceKeys();
        $this->forgetPublishedCache();
    }

    /**
     * 街ヘッダと更新履歴モーダルへ出す公開済み更新。
     */
    public function published(int $limit = 20): Collection
    {
        if (! Schema::hasTable('top_updates')) {
            return collect();
        }

        $query = TopUpdate::query()
            ->where('is_active', true)
            ->whereDate('published_on', '<=', today('Asia/Tokyo'))
            ->orderByDesc('published_on')
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if (Schema::hasColumn('top_updates', 'is_dismissed')) {
            $query->where('is_dismissed', false);
        }

        // Eloquent Collectionを共有キャッシュへ保存すると、デプロイを跨いだ
        // unserialize時に__PHP_Incomplete_Classとなるため、DBから直接取得する。
        return $query->limit(max(1, min(20, $limit)))->get();
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

    private function supportsDeletionRegistry(): bool
    {
        return Schema::hasTable('game_settings');
    }

    private function deleteLegacyDismissedCandidates(): void
    {
        DB::transaction(function (): void {
            $dismissedUpdates = TopUpdate::query()
                ->where('is_dismissed', true)
                ->whereNotNull('source_key')
                ->orderBy('published_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($dismissedUpdates as $update) {
                $this->rememberDeletedSourceKey((string) $update->source_key);
                $update->delete();
            }
        });
    }

    private function rememberDeletedSourceKey(string $sourceKey): void
    {
        GameSetting::query()->updateOrCreate(
            ['setting_key' => $this->deletedSourceSettingKey($sourceKey)],
            [
                'label' => '削除済み更新候補',
                'description' => '管理画面で削除され、再生成を停止した街の更新候補です。',
                'value' => '1',
                'value_type' => 'boolean',
            ]
        );
    }

    private function pruneDeletedSourceKeys(): void
    {
        if (! $this->supportsDeletionRegistry()) {
            return;
        }

        $staleIds = GameSetting::query()
            ->where('setting_key', 'like', self::DELETED_SOURCE_SETTING_PREFIX . '%')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->slice(self::AUTO_IMPORT_LIMIT);

        if ($staleIds->isNotEmpty()) {
            GameSetting::query()->whereKey($staleIds->all())->delete();
        }
    }

    private function deletedSourceSettingKey(string $sourceKey): string
    {
        return self::DELETED_SOURCE_SETTING_PREFIX . sha1($sourceKey);
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
