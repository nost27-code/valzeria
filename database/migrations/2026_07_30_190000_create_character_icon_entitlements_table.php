<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_icon_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_icon_design_request_id')
                ->nullable()
                ->constrained('character_icon_design_requests', indexName: 'icon_entitlement_request_fk')
                ->nullOnDelete();
            $table->foreignId('granted_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'icon_entitlement_granter_fk')
                ->nullOnDelete();
            $table->string('icon_set_key', 80);
            $table->string('previous_icon_path')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('icon_set_key', 'icon_entitlement_set_unique');
            $table->unique('character_icon_design_request_id', 'icon_entitlement_request_unique');
            $table->index(['character_id', 'revoked_at'], 'icon_entitlement_character_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_icon_entitlements');
    }
};
