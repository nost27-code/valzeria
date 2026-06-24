<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contact_messages')) {
            return;
        }

        Schema::table('contact_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('contact_messages', 'source')) {
                $table->string('source', 30)->default('form')->after('recipient_email');
            }

            if (!Schema::hasColumn('contact_messages', 'external_uid')) {
                $table->string('external_uid', 255)->nullable()->after('source');
            }

            if (!Schema::hasColumn('contact_messages', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('external_uid');
            }
        });

        Schema::table('contact_messages', function (Blueprint $table) {
            $table->unique(['source', 'external_uid'], 'contact_messages_source_uid_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('contact_messages')) {
            return;
        }

        Schema::table('contact_messages', function (Blueprint $table) {
            $table->dropUnique('contact_messages_source_uid_unique');
        });

        Schema::table('contact_messages', function (Blueprint $table) {
            if (Schema::hasColumn('contact_messages', 'received_at')) {
                $table->dropColumn('received_at');
            }

            if (Schema::hasColumn('contact_messages', 'external_uid')) {
                $table->dropColumn('external_uid');
            }

            if (Schema::hasColumn('contact_messages', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
