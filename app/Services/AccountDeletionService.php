<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountDeletionService
{
    /**
     * Delete the signed-in user's account and all character-owned data.
     */
    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $characterIds = $user->characters()->pluck('id');

            if ($characterIds->isNotEmpty()) {
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
