<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncPermissions();
    }

    public function down(): void
    {
        if (! $this->hasRequiredTables()) {
            return;
        }

        $jobIds = array_keys($this->permissions());
        DB::table('job_weapon_permissions')->whereIn('job_id', $jobIds)->delete();
        DB::table('job_armor_permissions')->whereIn('job_id', $jobIds)->delete();
    }

    private function syncPermissions(): void
    {
        if (! $this->hasRequiredTables()) {
            return;
        }

        $permissions = $this->permissions();
        if ($permissions === []) {
            return;
        }

        $now = now();

        foreach ($permissions as $jobId => $permission) {
            $jobName = $permission['name'] ?? null;
            if (! $jobName || ! $this->jobExists((int) $jobId, $jobName)) {
                continue;
            }

            DB::table('job_weapon_permissions')->where('job_id', $jobId)->delete();
            DB::table('job_armor_permissions')->where('job_id', $jobId)->delete();

            foreach ($permission['weapons'] ?? [] as $category) {
                DB::table('job_weapon_permissions')->insert([
                    'job_id' => $jobId,
                    'weapon_category' => $category,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($permission['armors'] ?? [] as $category) {
                DB::table('job_armor_permissions')->insert([
                    'job_id' => $jobId,
                    'armor_category' => $category,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function hasRequiredTables(): bool
    {
        return Schema::hasTable('job_classes')
            && Schema::hasTable('job_weapon_permissions')
            && Schema::hasTable('job_armor_permissions');
    }

    private function jobExists(int $jobId, string $jobName): bool
    {
        return DB::table('job_classes')
            ->where('id', $jobId)
            ->where('name', $jobName)
            ->exists();
    }

    private function permissions(): array
    {
        $permissions = config('job_equipment_permissions.high_rank');
        if (is_array($permissions)) {
            return $permissions;
        }

        $path = config_path('job_equipment_permissions.php');
        if (! is_file($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config['high_rank'] ?? null) ? $config['high_rank'] : [];
    }
};
