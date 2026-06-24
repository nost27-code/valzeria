<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            $hasKeiseki = Schema::hasColumn('characters', 'keiseki');
            $hasPaidKeiseki = Schema::hasColumn('characters', 'paid_keiseki');
            $hasFreeKeiseki = Schema::hasColumn('characters', 'free_keiseki');

            if (!$hasKeiseki || !$hasPaidKeiseki || !$hasFreeKeiseki) {
                Schema::table('characters', function (Blueprint $table) use ($hasKeiseki, $hasPaidKeiseki, $hasFreeKeiseki) {
                    if (!$hasKeiseki) {
                        $table->unsignedInteger('keiseki')
                            ->default(0)
                            ->after('money')
                            ->comment('課金通貨: 輝石');
                    }

                    if (!$hasPaidKeiseki) {
                        $table->unsignedInteger('paid_keiseki')
                            ->default(0)
                            ->after($hasKeiseki ? 'keiseki' : 'money')
                            ->comment('有償輝石');
                    }

                    if (!$hasFreeKeiseki) {
                        $table->unsignedInteger('free_keiseki')
                            ->default(0)
                            ->after($hasKeiseki ? 'keiseki' : 'money')
                            ->comment('無償輝石');
                    }
                });
            }
        }

        if (Schema::hasTable('characters')) {
            if (Schema::hasColumn('characters', 'keiseki') && Schema::hasColumn('characters', 'free_keiseki')) {
                DB::table('characters')
                    ->where('free_keiseki', 0)
                    ->where('keiseki', '>', 0)
                    ->update(['free_keiseki' => DB::raw('keiseki')]);
            }
        }

        if (!Schema::hasTable('daily_kiseki_drop_logs')) {
            Schema::create('daily_kiseki_drop_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->date('drop_date');
                $table->unsignedTinyInteger('dropped_count')->default(0);
                $table->timestamps();

                $table->unique(['character_id', 'drop_date'], 'daily_kiseki_character_date_unique');
            });
        }

        if (!Schema::hasTable('kiseki_transactions')) {
            Schema::create('kiseki_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('kiseki_type', 20);
                $table->integer('amount');
                $table->string('transaction_type', 50);
                $table->string('source_type', 50)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('enemy_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('enemy_level')->nullable();
                $table->unsignedInteger('character_level')->nullable();
                $table->unsignedTinyInteger('daily_dropped_count')->nullable();
                $table->string('description')->nullable();
                $table->timestamps();

                $table->index(['character_id', 'transaction_type']);
                $table->index(['created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kiseki_transactions');
        Schema::dropIfExists('daily_kiseki_drop_logs');

        if (!Schema::hasTable('characters')) {
            return;
        }

        $hasFreeKeiseki = Schema::hasColumn('characters', 'free_keiseki');
        $hasPaidKeiseki = Schema::hasColumn('characters', 'paid_keiseki');

        if (!$hasFreeKeiseki && !$hasPaidKeiseki) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) use ($hasFreeKeiseki, $hasPaidKeiseki) {
            if ($hasFreeKeiseki) {
                $table->dropColumn('free_keiseki');
            }

            if ($hasPaidKeiseki) {
                $table->dropColumn('paid_keiseki');
            }
        });
    }
};
