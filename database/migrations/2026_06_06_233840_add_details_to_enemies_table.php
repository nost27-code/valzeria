<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('enemies', function (Blueprint $table) {
            $table->string('role')->nullable()->after('is_boss')->comment('役割（雑魚、レア敵など）');
            $table->string('type_name')->nullable()->after('role')->comment('型（耐久型、物理型など）');
            $table->string('element')->nullable()->after('type_name')->comment('属性（自然、無、闇など）');
            $table->string('action_pattern')->nullable()->after('element')->comment('行動傾向');
            $table->string('drop_type')->nullable()->after('action_pattern')->comment('レアドロップ区分');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enemies', function (Blueprint $table) {
            $table->dropColumn(['role', 'type_name', 'element', 'action_pattern', 'drop_type']);
        });
    }
};
