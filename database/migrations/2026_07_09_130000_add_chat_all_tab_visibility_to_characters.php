<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('characters', 'chat_all_tab_visibility')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->json('chat_all_tab_visibility')->nullable()->after('private_chat_theme');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('characters', 'chat_all_tab_visibility')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('chat_all_tab_visibility');
        });
    }
};
