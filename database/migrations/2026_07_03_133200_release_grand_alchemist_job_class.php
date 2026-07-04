<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        $now = now();

        DB::table('job_classes')
            ->where('id', 49)
            ->update([
                'is_hidden' => false,
                'is_active' => true,
                'description' => '錬金術と魔盗の技を極め、素材と魔力を一撃の爆装へ変える。',
                'updated_at' => $now,
            ]);

        if (! Schema::hasTable('job_requirements')) {
            return;
        }

        foreach (['錬金術師', '魔盗士'] as $requiredJobName) {
            $requiredJobId = DB::table('job_classes')->where('name', $requiredJobName)->value('id');
            if (! $requiredJobId) {
                continue;
            }

            DB::table('job_requirements')->updateOrInsert(
                [
                    'job_id' => 49,
                    'requirement_type' => 'master_job',
                    'required_job_id' => $requiredJobId,
                ],
                [
                    'required_value' => null,
                    'required_key' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->where('id', 49)
            ->update([
                'is_hidden' => true,
                'is_active' => false,
                'description' => '未公開職業データ。正式解放前の調整用。',
                'updated_at' => now(),
            ]);

        if (! Schema::hasTable('job_requirements')) {
            return;
        }

        DB::table('job_requirements')
            ->where('job_id', 49)
            ->where('requirement_type', 'master_job')
            ->whereIn('required_job_id', function ($query) {
                $query->select('id')
                    ->from('job_classes')
                    ->whereIn('name', ['錬金術師', '魔盗士']);
            })
            ->delete();
    }
};
