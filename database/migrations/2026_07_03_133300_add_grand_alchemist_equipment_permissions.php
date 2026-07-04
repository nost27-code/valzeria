<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->seedPermissions(
            ['magic_device', 'staff', 'gun', 'dagger'],
            ['clothes', 'robe', 'cloak', 'light_armor']
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        $jobId = DB::table('job_classes')->where('name', '大錬金術師')->value('id');
        if (! $jobId) {
            return;
        }

        if (Schema::hasTable('job_weapon_permissions')) {
            DB::table('job_weapon_permissions')
                ->where('job_id', $jobId)
                ->whereIn('weapon_category', ['magic_device', 'staff', 'gun', 'dagger'])
                ->delete();
        }

        if (Schema::hasTable('job_armor_permissions')) {
            DB::table('job_armor_permissions')
                ->where('job_id', $jobId)
                ->whereIn('armor_category', ['clothes', 'robe', 'cloak', 'light_armor'])
                ->delete();
        }
    }

    private function seedPermissions(array $weapons, array $armors): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        $jobId = DB::table('job_classes')->where('name', '大錬金術師')->value('id');
        if (! $jobId) {
            return;
        }

        $now = now();

        if (Schema::hasTable('job_weapon_permissions')) {
            foreach ($weapons as $category) {
                DB::table('job_weapon_permissions')->updateOrInsert(
                    ['job_id' => $jobId, 'weapon_category' => $category],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        if (Schema::hasTable('job_armor_permissions')) {
            foreach ($armors as $category) {
                DB::table('job_armor_permissions')->updateOrInsert(
                    ['job_id' => $jobId, 'armor_category' => $category],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }
};
