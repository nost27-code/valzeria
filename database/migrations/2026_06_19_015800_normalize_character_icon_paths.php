<?php

use App\Support\CharacterIconCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeTable('characters');
        $this->normalizeTable('champ_states');
    }

    public function down(): void
    {
        //
    }

    private function normalizeTable(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'icon_path')) {
            return;
        }

        DB::table($table)
            ->whereNull('icon_path')
            ->orWhere('icon_path', '')
            ->update(['icon_path' => CharacterIconCatalog::DEFAULT_ICON, 'updated_at' => now()]);

        DB::table($table)
            ->where('icon_path', 'like', '%images/chara/chara_%')
            ->orderBy('id')
            ->select('id', 'icon_path')
            ->chunkById(100, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $normalized = CharacterIconCatalog::normalize($row->icon_path);
                    if (!CharacterIconCatalog::isSelectable($normalized)) {
                        $normalized = CharacterIconCatalog::DEFAULT_ICON;
                    }

                    if ($normalized === $row->icon_path) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'icon_path' => $normalized,
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
