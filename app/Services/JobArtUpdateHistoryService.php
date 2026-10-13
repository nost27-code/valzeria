<?php

namespace App\Services;

use Illuminate\Support\Collection;

class JobArtUpdateHistoryService
{
    public function currentFor(string $key, bool $includeUnreleased = false): ?array
    {
        return collect(config('job_art_updates.'.$key, []))
            ->first(fn (array $update): bool => $update['status'] === 'published' || $includeUnreleased);
    }

    /** All learned/unlearned arts share this read-only history. */
    public function forDisplay(bool $includeUnreleased = false): Collection
    {
        $entries = collect();
        foreach (config('job_art_updates', []) as $key => $updates) {
            [, , $name] = explode(':', $key, 3);
            foreach ($updates as $update) {
                if ($update['status'] !== 'published' && ! $includeUnreleased) {
                    continue;
                }
                $entries->push($update + ['name' => $name]);
            }
        }

        return $entries->groupBy('date')->sortKeysDesc()
            ->map(fn (Collection $entries, string $date): array => ['date' => $date, 'entries' => $entries])
            ->values();
    }
}
