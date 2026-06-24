<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('npc_procurement_request_templates')) {
            Schema::create('npc_procurement_request_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('city_id')->nullable();
                $table->string('title', 120);
                $table->string('requester_name', 120);
                $table->string('requester_type', 50)->default('npc');
                $table->text('description')->nullable();
                $table->string('purpose_label', 100)->nullable();
                $table->integer('frequency_weight')->default(100);
                $table->integer('min_character_level')->default(1);
                $table->integer('max_character_level')->nullable();
                $table->integer('duration_hours')->default(24);
                $table->integer('reward_gold_on_complete')->default(0);
                $table->integer('reward_association_point_on_complete')->default(0);
                $table->json('reward_items_json')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('display_order')->default(0);
                $table->timestamps();

                $table->index(['city_id', 'is_active'], 'npc_req_tpl_city_active_idx');
                $table->index('frequency_weight', 'npc_req_tpl_weight_idx');
                $table->index(['min_character_level', 'max_character_level'], 'npc_req_tpl_level_idx');
                $table->unique(['title', 'requester_name'], 'npc_req_tpl_unique_title_requester');
            });
        }

        if (! Schema::hasTable('npc_procurement_request_template_materials')) {
            Schema::create('npc_procurement_request_template_materials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('npc_procurement_request_template_id');
                $table->unsignedBigInteger('material_id');
                $table->integer('required_quantity');
                $table->integer('reward_gold_per_unit')->default(0);
                $table->timestamps();

                $table->index('npc_procurement_request_template_id', 'npc_req_tpl_mat_template_idx');
                $table->index('material_id', 'npc_req_tpl_mat_material_idx');
                $table->unique(['npc_procurement_request_template_id', 'material_id'], 'npc_req_tpl_mat_unique');
            });
        }

        if (Schema::hasTable('npc_procurement_requests')) {
            Schema::table('npc_procurement_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('npc_procurement_requests', 'npc_procurement_request_template_id')) {
                    $table->unsignedBigInteger('npc_procurement_request_template_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('npc_procurement_requests', 'generated_for_date')) {
                    $table->date('generated_for_date')->nullable()->after('display_order');
                }
                if (! Schema::hasColumn('npc_procurement_requests', 'generated_batch_key')) {
                    $table->string('generated_batch_key', 100)->nullable()->after('generated_for_date');
                }
            });

            Schema::table('npc_procurement_requests', function (Blueprint $table) {
                $table->index(['npc_procurement_request_template_id', 'status'], 'npc_requests_template_status_idx');
                $table->index('generated_for_date', 'npc_requests_generated_for_date_idx');
                $table->index('generated_batch_key', 'npc_requests_batch_key_idx');
            });

            try {
                Schema::table('npc_procurement_requests', function (Blueprint $table) {
                    $table->dropUnique('npc_requests_unique_title_requester');
                });
            } catch (Throwable) {
                // Older installs may not have this legacy fixed-request uniqueness index.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('npc_procurement_requests')) {
            Schema::table('npc_procurement_requests', function (Blueprint $table) {
                if (Schema::hasColumn('npc_procurement_requests', 'npc_procurement_request_template_id')) {
                    $table->dropColumn('npc_procurement_request_template_id');
                }
                if (Schema::hasColumn('npc_procurement_requests', 'generated_for_date')) {
                    $table->dropColumn('generated_for_date');
                }
                if (Schema::hasColumn('npc_procurement_requests', 'generated_batch_key')) {
                    $table->dropColumn('generated_batch_key');
                }
            });
        }

        Schema::dropIfExists('npc_procurement_request_template_materials');
        Schema::dropIfExists('npc_procurement_request_templates');
    }
};
