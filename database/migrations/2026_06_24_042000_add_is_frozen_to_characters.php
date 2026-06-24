<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->boolean('is_frozen')->default(false)->after('id');
            $table->text('freeze_reason')->nullable()->after('is_frozen');
            $table->timestamp('frozen_at')->nullable()->after('freeze_reason');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['is_frozen', 'freeze_reason', 'frozen_at']);
        });
    }
};
