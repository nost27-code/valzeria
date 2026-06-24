<?php

use App\Support\CharacterIconCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->compactTable('characters');
        $this->compactTable('champ_states');
    }

    public function down(): void
    {
        //
    }

    private function compactTable(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'icon_path')) {
            return;
        }

        DB::table($table)
            ->whereNotNull('icon_path')
            ->orderBy('id')
            ->select('id', 'icon_path')
            ->chunkById(100, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $path = '/' . ltrim((string) $row->icon_path, '/');
                    if (preg_match('/\A\/images\/chara\/chara_(\d{1,3})\.webp\z/', $path, $matches) !== 1) {
                        continue;
                    }

                    $number = (int) $matches[1];
                    if ($number >= 27) {
                        $number--;
                    }

                    $nextPath = $number >= 1 && $number <= 156
                        ? sprintf('/images/chara/chara_%03d.webp', $number)
                        : CharacterIconCatalog::DEFAULT_ICON;

                    if ($nextPath === $row->icon_path) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'icon_path' => $nextPath,
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
