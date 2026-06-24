<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        $path = database_path('data/shop_equipment_master.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['external_item_id']) || !array_key_exists('description', $row)) {
                continue;
            }

            DB::table('items')
                ->where('external_item_id', (string) $row['external_item_id'])
                ->update([
                    'description' => (string) $row['description'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Master-data text refresh only. Keep current descriptions on rollback.
    }
};
