<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->integer('mp_cost')->default(0)->after('job_id');
        });

        // 既存スキルのmp_costを一括更新（職業ランクに基づく）
        // SQLite等でJOIN UPDATEが使えない場合を考慮し、一件ずつ、またはクエリビルダで取得して更新する
        $skills = DB::table('skills')->get();
        foreach ($skills as $skill) {
            if (!$skill->job_id) continue;
            
            $job = DB::table('job_classes')->where('id', $skill->job_id)->first();
            if (!$job) continue;

            $cost = 0;
            switch ($job->rank) {
                case 'Normal':
                    $cost = 3;
                    break;
                case 'Middle':
                    $cost = 6;
                    break;
                case 'Advanced':
                    $cost = 10;
                    break;
                case 'Legend':
                    $cost = 15;
                    break;
            }

            DB::table('skills')->where('id', $skill->id)->update(['mp_cost' => $cost]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('mp_cost');
        });
    }
};
