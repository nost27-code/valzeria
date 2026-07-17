<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_anomaly_cases', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 64);
            $table->char('fingerprint', 64)->unique();
            $table->string('severity', 16)->default('warning');
            $table->string('status', 20)->default('detected');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_key', 128)->nullable();
            $table->string('title');
            $table->text('summary');
            $table->json('evidence')->nullable();
            $table->unsignedInteger('detection_count')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_detected_at'], 'security_anomaly_cases_status_detected_idx');
            $table->index(['rule_key', 'last_detected_at'], 'security_anomaly_cases_rule_detected_idx');
            $table->index(['character_id', 'last_detected_at'], 'security_anomaly_cases_character_detected_idx');
            $table->index(['user_id', 'last_detected_at'], 'security_anomaly_cases_user_detected_idx');
            $table->index(['subject_key', 'last_detected_at'], 'security_anomaly_cases_subject_detected_idx');
        });

        Schema::create('security_anomaly_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('security_anomaly_case_id')->constrained('security_anomaly_cases')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['security_anomaly_case_id', 'created_at'], 'security_anomaly_events_case_created_idx');
        });

        Schema::create('security_login_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('ip_hash', 64);
            $table->string('masked_ip', 64);
            $table->date('observed_date');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->unsignedInteger('observation_count')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'ip_hash', 'observed_date'], 'security_login_user_ip_date_unique');
            $table->index(['ip_hash', 'last_observed_at'], 'security_login_ip_observed_idx');
            $table->index('last_observed_at');
        });

        Schema::create('security_inventory_snapshots', function (Blueprint $table) {
            $table->foreignId('character_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('equipment_count')->default(0);
            $table->unsignedBigInteger('material_quantity')->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::table('battle_logs', function (Blueprint $table) {
            $table->index(['character_id', 'created_at'], 'battle_logs_character_created_idx');
        });

        Schema::table('kiseki_transactions', function (Blueprint $table) {
            $table->index(['character_id', 'created_at'], 'kiseki_transactions_character_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('kiseki_transactions')) {
            Schema::table('kiseki_transactions', fn (Blueprint $table) => $table->dropIndex('kiseki_transactions_character_created_idx'));
        }
        if (Schema::hasTable('battle_logs')) {
            Schema::table('battle_logs', fn (Blueprint $table) => $table->dropIndex('battle_logs_character_created_idx'));
        }

        Schema::dropIfExists('security_inventory_snapshots');
        Schema::dropIfExists('security_login_observations');
        Schema::dropIfExists('security_anomaly_case_events');
        Schema::dropIfExists('security_anomaly_cases');
    }
};
