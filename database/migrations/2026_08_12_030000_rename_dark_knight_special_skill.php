<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_KEY = 'dark_knight';

    private const OLD_NAME = '暗黒剣';

    private const NEW_NAME = '冥血斬';

    public function up(): void
    {
        $this->renameSpecialSkill(self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        $this->renameSpecialSkill(self::NEW_NAME, self::OLD_NAME);
    }

    private function renameSpecialSkill(string $from, string $to): void
    {
        if (! Schema::hasTable('job_classes') || ! Schema::hasTable('skills')) {
            return;
        }

        $jobId = DB::table('job_classes')
            ->where('key', self::JOB_KEY)
            ->value('id');

        if ($jobId === null) {
            return;
        }

        DB::table('skills')
            ->where('job_id', $jobId)
            ->where('skill_type', 'special')
            ->where('name', $from)
            ->update([
                'name' => $to,
                'updated_at' => now(),
            ]);
    }
};
