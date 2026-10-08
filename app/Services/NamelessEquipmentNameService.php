<?php

namespace App\Services;

use RuntimeException;

class NamelessEquipmentNameService
{
    public function assertAllowed(string $name, ?string $existingName = null): void
    {
        // 既存個体の名前をそのまま引き継ぐ操作は許容する。別表記や別個体には適用しない。
        if ($name === trim((string) $existingName)) {
            return;
        }
        $normalized = $this->normalizeForComparison($name);
        $compact = $this->compact($normalized);
        if ($compact === '') {
            return;
        }

        foreach (config('nameless_equipment_names.blocked_fragments', []) as $word) {
            $fragment = $this->compact($this->normalizeForComparison($word));
            if ($fragment !== '' && str_contains($compact, $fragment)) {
                throw new RuntimeException('この名前には使用できない言葉が含まれています。別の名前にしてください。');
            }
        }

        foreach (config('nameless_equipment_names.blocked_latin_words', []) as $word) {
            $word = $this->compact($this->normalizeForComparison($word));
            if ($word === '') {
                continue;
            }
            $pattern = '/(?<![a-z0-9])'.preg_quote($word, '/').'(?![a-z0-9])/u';
            if (preg_match($pattern, $normalized) || preg_match($pattern, $compact)) {
                throw new RuntimeException('この名前には使用できない言葉が含まれています。別の名前にしてください。');
            }
        }
    }

    private function normalizeForComparison(string $value): string
    {
        $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        return mb_strtolower(mb_convert_kana($value, 'c', 'UTF-8'), 'UTF-8');
    }

    private function compact(string $value): string
    {
        // ゼロ幅文字や結合文字も除き、記号で分割した禁止語を検出する。
        return preg_replace('/[\p{Z}\p{P}\p{S}\p{C}\p{M}]+/u', '', $value);
    }
}
