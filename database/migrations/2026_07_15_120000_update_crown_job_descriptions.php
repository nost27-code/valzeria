<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREVIOUS_DESCRIPTION = '未公開職業データ。正式解放前の調整用。';
    private const DESCRIPTION = '冠位の証を持つ者だけが至れる、各道を極めた者のための冠位職です。';

    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->where('rank', 'crown')
            ->update([
                'description' => self::DESCRIPTION,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->where('rank', 'crown')
            ->where('description', self::DESCRIPTION)
            ->update([
                'description' => self::PREVIOUS_DESCRIPTION,
                'updated_at' => now(),
            ]);
    }
};
