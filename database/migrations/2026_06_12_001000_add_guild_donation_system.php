<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'guild_donation_total')) {
                $table->unsignedBigInteger('guild_donation_total')->default(0)->after('money');
            }
        });

        Schema::create('guild_donation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('donation_amount');
            $table->unsignedBigInteger('donation_total_after');
            $table->string('rank_after', 30);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_donation_logs');

        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'guild_donation_total')) {
                $table->dropColumn('guild_donation_total');
            }
        });
    }
};
