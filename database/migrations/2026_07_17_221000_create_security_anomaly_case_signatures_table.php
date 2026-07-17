<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_anomaly_case_signatures')) {
            Schema::create('security_anomaly_case_signatures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('security_anomaly_case_id');
                $table->char('fingerprint', 64)->unique();
                $table->dateTime('created_at');

                $table->index(['security_anomaly_case_id', 'created_at'], 'security_anomaly_signatures_case_created_idx');
            });
        }

        $hasCaseForeignKey = collect(Schema::getForeignKeys('security_anomaly_case_signatures'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['security_anomaly_case_id']);

        if (! $hasCaseForeignKey) {
            Schema::table('security_anomaly_case_signatures', function (Blueprint $table) {
                $table->foreign('security_anomaly_case_id', 'security_anomaly_signatures_case_fk')
                    ->references('id')
                    ->on('security_anomaly_cases')
                    ->cascadeOnDelete();
            });
        }

        DB::table('security_anomaly_cases')->orderBy('id')->each(function ($case): void {
            DB::table('security_anomaly_case_signatures')->insertOrIgnore([
                'security_anomaly_case_id' => $case->id,
                'fingerprint' => $case->fingerprint,
                'created_at' => $case->first_detected_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_anomaly_case_signatures');
    }
};
