<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasRequiredColumns()) {
            return;
        }

        $payload = [
            'max_enhance' => 5,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('items', 'description')) {
            $payload['description'] = DB::raw("REPLACE(description, '+3まで強化可', '+5まで強化可')");
        }

        DB::table('items')
            ->where('source_type', 'star_tree_tower_reward')
            ->where('type', 'weapon')
            ->update($payload);
    }

    public function down(): void
    {
        if (! $this->hasRequiredColumns()) {
            return;
        }

        $payload = [
            'max_enhance' => 3,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('items', 'description')) {
            $payload['description'] = DB::raw("REPLACE(description, '+5まで強化可', '+3まで強化可')");
        }

        DB::table('items')
            ->where('source_type', 'star_tree_tower_reward')
            ->where('type', 'weapon')
            ->update($payload);
    }

    private function hasRequiredColumns(): bool
    {
        return Schema::hasTable('items')
            && Schema::hasColumn('items', 'source_type')
            && Schema::hasColumn('items', 'type')
            && Schema::hasColumn('items', 'max_enhance')
            && Schema::hasColumn('items', 'updated_at');
    }
};
