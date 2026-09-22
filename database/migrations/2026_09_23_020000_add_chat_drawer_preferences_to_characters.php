<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('characters', 'chat_drawer_preferences')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->json('chat_drawer_preferences')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('characters', 'chat_drawer_preferences')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('chat_drawer_preferences');
        });
    }
};
