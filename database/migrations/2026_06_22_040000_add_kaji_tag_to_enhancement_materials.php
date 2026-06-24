<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $patterns = ['強化石', '守護石'];

        foreach ($patterns as $pattern) {
            $materials = DB::table('materials')
                ->where('name', 'like', '%' . $pattern . '%')
                ->get(['id', 'usage_tags']);

            foreach ($materials as $mat) {
                $tags = json_decode($mat->usage_tags ?? '[]', true) ?? [];
                if (! in_array('鍛冶', $tags, true)) {
                    $tags[] = '鍛冶';
                    DB::table('materials')->where('id', $mat->id)->update([
                        'usage_tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $patterns = ['強化石', '守護石'];

        foreach ($patterns as $pattern) {
            $materials = DB::table('materials')
                ->where('name', 'like', '%' . $pattern . '%')
                ->get(['id', 'usage_tags']);

            foreach ($materials as $mat) {
                $tags = json_decode($mat->usage_tags ?? '[]', true) ?? [];
                $tags = array_values(array_filter($tags, fn ($t) => $t !== '鍛冶'));
                DB::table('materials')->where('id', $mat->id)->update([
                    'usage_tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }
};
