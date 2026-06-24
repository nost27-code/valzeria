<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // kiseki = paid_kiseki + free_kiseki に全キャラクター分を同期
        DB::statement('UPDATE characters SET kiseki = COALESCE(paid_kiseki, 0) + COALESCE(free_kiseki, 0)');
    }

    public function down(): void
    {
        //
    }
};
