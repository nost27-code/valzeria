<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountDeletionService
{
    public function __construct(
        private readonly SixHeroRankingService $sixHeroRankingService,
    ) {}

    /**
     * Delete the account, preserving anonymous shared map history.
     */
    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $characterIds = $user->characters()->orderBy('id')->lockForUpdate()->pluck('id');

            if ($characterIds->isNotEmpty()) {
                $this->sixHeroRankingService->removeCharactersFromOpenSeasons(
                    $characterIds->map(static fn ($id): int => (int) $id)->all()
                );

                $mapIds = DB::table('exploration_maps')->whereIn('owner_character_id', $characterIds)->pluck('id');
                // Serialize against new map admissions before closing publications.
                DB::table('town_map_registrations')->whereIn('map_id', $mapIds)->orderBy('id')->lockForUpdate()->get(['id']);
                $pending = DB::table('map_exploration_batches')
                    ->whereIn('status', ['reserved', 'processing'])
                    ->where(fn ($query) => $query->whereIn('map_id', $mapIds)->orWhereIn('character_id', $characterIds))
                    ->lockForUpdate()->first(['id']);
                if ($pending) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'confirmation' => '地図探索の処理が残っています。探索結果の確定後に、もう一度退会してください。',
                    ]);
                }
                DB::table('town_map_registrations')->whereIn('map_id', $mapIds)
                    ->where('status', 'published')->where('expires_at', '>', now())->where('remaining_explorations', '>', 0)
                    ->update(['status' => 'withdrawn', 'updated_at' => now()]);
                // Never expose an unpublished map through the recently-closed list.
                // Previously closed publications keep their original closed time.
                DB::table('town_map_registrations')->whereIn('map_id', $mapIds)
                    ->whereIn('status', ['surveying', 'surveyed'])
                    ->update(['status' => 'discarded', 'updated_at' => now()]);
                DB::table('exploration_maps')->whereIn('id', $mapIds)
                    ->update(['status' => 'withdrawn', 'updated_at' => now()]);

                $publicLogs = DB::table('public_logs')->whereIn('character_id', $characterIds);

                if (Schema::hasColumn('public_logs', 'receiver_id')) {
                    $publicLogs->orWhereIn('receiver_id', $characterIds);
                }

                $publicLogs->delete();
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            if (Schema::hasTable('password_reset_tokens') && $user->email) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }

            $user->delete();
        });
    }
}
