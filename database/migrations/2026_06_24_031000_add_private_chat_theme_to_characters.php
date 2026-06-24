<?php

use App\Support\PrivateChatThemeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'private_chat_theme')) {
                $table->string('private_chat_theme', 40)
                    ->default(PrivateChatThemeCatalog::DEFAULT_KEY)
                    ->after('profile_frame_theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'private_chat_theme')) {
                $table->dropColumn('private_chat_theme');
            }
        });
    }
};
