<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCALE_VERSION = 2;
    private const SCALE_FACTOR = 8;

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('armor_performance_scale_version')->nullable()->after('weapon_offense_scale_version');
        });

        Schema::table('character_items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('armor_performance_scale_version')->nullable()->after('weapon_offense_scale_version');
        });

        DB::transaction(function (): void {
            $needsScale = fn ($query) => $query
                ->whereNull('armor_performance_scale_version')
                ->orWhere('armor_performance_scale_version', '<', self::SCALE_VERSION);

            DB::table('items')
                ->where('type', 'armor')
                ->where($needsScale)
                ->update([
                    'hp_bonus' => DB::raw('COALESCE(hp_bonus, 0) * ' . self::SCALE_FACTOR),
                    'mp_bonus' => DB::raw('COALESCE(mp_bonus, 0) * ' . self::SCALE_FACTOR),
                    'str_bonus' => DB::raw('COALESCE(str_bonus, 0) * ' . self::SCALE_FACTOR),
                    'def_bonus' => DB::raw('COALESCE(def_bonus, 0) * ' . self::SCALE_FACTOR),
                    'agi_bonus' => DB::raw('COALESCE(agi_bonus, 0) * ' . self::SCALE_FACTOR),
                    'mag_bonus' => DB::raw('COALESCE(mag_bonus, 0) * ' . self::SCALE_FACTOR),
                    'spr_bonus' => DB::raw('COALESCE(spr_bonus, 0) * ' . self::SCALE_FACTOR),
                    'luk_bonus' => DB::raw('COALESCE(luk_bonus, 0) * ' . self::SCALE_FACTOR),
                    'armor_performance_scale_version' => self::SCALE_VERSION,
                ]);

            DB::table('character_items')
                ->whereIn('item_id', DB::table('items')->where('type', 'armor')->select('id'))
                ->where($needsScale)
                ->update([
                    // 旧方式で保存されたランダム銘補正だけを対象にする。動的な銘・強化は8倍化済みマスタから再計算される。
                    'affix_hp_bonus' => DB::raw('COALESCE(affix_hp_bonus, 0) * ' . self::SCALE_FACTOR),
                    'affix_str_bonus' => DB::raw('COALESCE(affix_str_bonus, 0) * ' . self::SCALE_FACTOR),
                    'affix_def_bonus' => DB::raw('COALESCE(affix_def_bonus, 0) * ' . self::SCALE_FACTOR),
                    'affix_mag_bonus' => DB::raw('COALESCE(affix_mag_bonus, 0) * ' . self::SCALE_FACTOR),
                    'affix_spr_bonus' => DB::raw('COALESCE(affix_spr_bonus, 0) * ' . self::SCALE_FACTOR),
                    'affix_agi_bonus' => DB::raw('COALESCE(affix_agi_bonus, 0) * ' . self::SCALE_FACTOR),
                    'affix_luk_bonus' => DB::raw('COALESCE(affix_luk_bonus, 0) * ' . self::SCALE_FACTOR),
                    'armor_performance_scale_version' => self::SCALE_VERSION,
                ]);
        });
    }

    public function down(): void
    {
        // 実運用での数値変更後に機械的に1/8へ戻すとデータを壊すため、この移行はバックアップから復旧する。
        throw new RuntimeException('防具能力8倍化は不可逆です。リリース前バックアップから復旧してください。');
    }
};
