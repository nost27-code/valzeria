<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'help.sections.exploration_metrics.body';

    private const OLD_DEFAULT = '<dd>エリアの難易度や敵の強さを示す指標です。危険度が高いほど敵が手強く、敗北のリスクも上がります。一方で、高い危険度のエリアほど良い報酬が期待できます。</dd>';

    private const OLD_OVERRIDE = '<dd>エリアの難易度や敵の強さを示す指標です。危険度が高いほど敵が手強くなっていき、敗北のリスクも上がります。一方で、高い危険度のエリアほど良い報酬が期待できます。</dd>';

    private const CLARIFIED = '<dd>エリアの難易度や敵の強さを示す指標です。危険度が高いほど敵が手強く、敗北のリスクも上がります。危険度が一定値に達すると、通常素材・通常装備のドロップ抽選が段階的に有利になります。危険度100%以上では、この通常ドロップ補正は同じです。装備のランクや品質は危険度では変化しません。探索深度が上がると、経験値・職業経験値が増加します。</dd>';

    public function up(): void
    {
        $this->replace([self::OLD_DEFAULT, self::OLD_OVERRIDE], self::CLARIFIED);
    }

    public function down(): void
    {
        $this->replace([self::CLARIFIED], self::OLD_DEFAULT);
    }

    /**
     * @param  array<int, string>  $search
     */
    private function replace(array $search, string $replacement): void
    {
        if (! Schema::hasTable('game_texts')) {
            return;
        }

        $value = DB::table('game_texts')->where('key', self::KEY)->value('value');
        if (! is_string($value)) {
            return;
        }

        $updated = str_replace($search, $replacement, $value);
        if ($updated === $value) {
            return;
        }

        DB::table('game_texts')
            ->where('key', self::KEY)
            ->update([
                'value' => $updated,
                'updated_at' => now(),
            ]);
    }
};
