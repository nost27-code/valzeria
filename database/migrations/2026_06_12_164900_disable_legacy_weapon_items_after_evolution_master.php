<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('data/weapon_evolution_master.json');
        $data = is_file($path)
            ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
            : [];

        $weaponIds = array_values(array_filter(array_column($data['weapon_master'] ?? [], 'weapon_id')));

        DB::table('items')
            ->where('type', 'weapon')
            ->where(function ($query) use ($weaponIds) {
                $query->whereNull('external_item_id');
                if (!empty($weaponIds)) {
                    $query->orWhereNotIn('external_item_id', $weaponIds);
                }
            })
            ->update([
                'is_active' => false,
                'is_shop_item' => false,
                'is_drop_enabled' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Legacy weapon records are intentionally left inactive.
    }
};
