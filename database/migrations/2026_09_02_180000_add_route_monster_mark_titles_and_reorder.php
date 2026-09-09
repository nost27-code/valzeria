<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Compatibility marker. The title rows are added after the nation-raid
        // honor titles by 2026_09_09_190000 to keep IDs deterministic.
    }

    public function down(): void
    {
        // Forward-only: no data is written by this compatibility marker.
    }
};
