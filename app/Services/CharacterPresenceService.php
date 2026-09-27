<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\DB;

class CharacterPresenceService
{
    public function touch(Character $character): void
    {
        if ($character->last_seen_at && $character->last_seen_at->diffInSeconds(now()) < 60) {
            return;
        }
        $seenAt = null;
        app(CharacterBackgroundOperation::class)->run((int) $character->id, function (Character $locked) use (&$seenAt): void {
            $seenAt = $locked->last_seen_at;
            if (! $seenAt || $seenAt->diffInSeconds(now()) >= 60) {
                $seenAt = now();
                // Update only presence; never save stale gameplay attributes.
                DB::table('characters')->where('id', $locked->id)
                    ->update(['last_seen_at' => $seenAt]);
            }
        });
        if ($seenAt !== null) {
            $character->last_seen_at = $seenAt;
        }
    }
}
