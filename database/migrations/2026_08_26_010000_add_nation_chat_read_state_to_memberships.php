<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nation_memberships') || ! Schema::hasTable('nation_chat_messages')) {
            throw new RuntimeException('国家チャット基盤migrationを先に適用してください。');
        }

        if (! Schema::hasColumn('nation_memberships', 'last_read_nation_chat_message_id')) {
            Schema::table('nation_memberships', function (Blueprint $table): void {
                $table->unsignedBigInteger('last_read_nation_chat_message_id')
                    ->nullable()
                    ->after('joined_at');
            });
        }

        $latestMessageIds = DB::table('nation_chat_messages')
            ->selectRaw('nation_id, MAX(id) AS latest_message_id')
            ->groupBy('nation_id')
            ->orderBy('nation_id')
            ->get();

        foreach ($latestMessageIds as $latestMessage) {
            DB::table('nation_memberships')
                ->where('nation_id', $latestMessage->nation_id)
                ->update(['last_read_nation_chat_message_id' => $latestMessage->latest_message_id]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('nation_memberships', 'last_read_nation_chat_message_id')) {
            return;
        }

        Schema::table('nation_memberships', function (Blueprint $table): void {
            $table->dropColumn('last_read_nation_chat_message_id');
        });
    }
};
