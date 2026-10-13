<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JobArtUpdateReadService
{
    /** Summarize the same per-art receipts used by effect explanations. */
    public function hasUnread(Collection $arts): bool
    {
        return $arts->contains(fn (Skill $art): bool => (bool) ($art->job_art_update['unread'] ?? false));
    }

    public function hasUnreadForCharacter(Character $character): bool
    {
        $history = app(JobArtUpdateHistoryService::class);
        $includeUnreleased = app()->environment(['local', 'testing']);
        if (! collect(array_keys(config('job_art_updates', [])))
            ->contains(fn (string $key): bool => $history->currentFor($key, $includeUnreleased) !== null)) {
            return false;
        }

        $jobArts = app(JobArtService::class);
        $arts = collect($jobArts->slotContexts())
            ->map(fn (string $context): string => $jobArts->availabilityContextForSlotContext($context))
            ->unique()
            ->flatMap(fn (string $context): Collection => $jobArts->availableArts($character, $context))
            ->unique('id')->values();
        $this->decorate($arts, (int) $character->user_id);

        return $this->hasUnread($arts);
    }

    public function updateFor(Skill $skill): ?array
    {
        if ($skill->skill_type !== 'job_art') {
            return null;
        }

        $latest = app(JobArtUpdateHistoryService::class)->currentFor(
            $skill->job_id.':'.$skill->learn_rank.':'.$skill->name,
            app()->environment(['local', 'testing']),
        );

        return $latest === null ? null : ['revision' => $latest['revision'], 'date' => $latest['date']];
    }

    /** One receipt query, even when cards are duplicated across battle contexts. */
    public function decorate(Collection $arts, int $userId): void
    {
        $updated = $arts->filter(fn (Skill $art): bool => $this->updateFor($art) !== null);
        if ($updated->isEmpty()) {
            return;
        }
        $reads = Schema::hasTable('job_art_update_reads')
            ? DB::table('job_art_update_reads')->where('user_id', $userId)
                ->whereIn('skill_id', $updated->pluck('id')->unique())
                ->whereIn('revision', $updated->map(fn (Skill $art) => $this->updateFor($art)['revision'])->unique())
                ->get()->keyBy(fn ($row): string => $row->skill_id.':'.$row->revision)
            : collect();

        foreach ($updated as $art) {
            $update = $this->updateFor($art);
            $art->setAttribute('job_art_update', $update + [
                'unread' => ! $reads->has($art->id.':'.$update['revision']),
            ]);
        }
    }

    public function markRead(int $userId, Skill $skill, string $revision): void
    {
        abort_unless(Schema::hasTable('job_art_update_reads'), 503, '既読を保存できませんでした。時間をおいて解説を開き直してください。');
        // The unique constraint makes repeated/concurrent opens idempotent.
        DB::table('job_art_update_reads')->insertOrIgnore([
            'user_id' => $userId, 'skill_id' => $skill->id,
            'revision' => $revision, 'read_at' => now(),
        ]);
    }
}
