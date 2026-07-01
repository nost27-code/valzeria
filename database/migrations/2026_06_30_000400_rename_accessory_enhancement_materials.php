<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RENAMES = [
        'ACC0007' => ['old' => '装飾強化石の欠片', 'new' => '調律石の欠片'],
        'ACC0008' => ['old' => '装飾強化石', 'new' => '調律石'],
        'ACC0009' => ['old' => '高純度装飾強化石', 'new' => '高純度調律石'],
    ];

    public function up(): void
    {
        $this->renameMaterials('new');
    }

    public function down(): void
    {
        $this->renameMaterials('old');
    }

    private function renameMaterials(string $direction): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();

        foreach (self::RENAMES as $materialCode => $names) {
            DB::table('materials')
                ->where('material_code', $materialCode)
                ->update([
                    'name' => $names[$direction],
                    'updated_at' => $now,
                ]);
        }
    }
};
