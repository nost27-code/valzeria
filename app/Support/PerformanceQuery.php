<?php

namespace App\Support;

final class PerformanceQuery
{
    public static function describe(string $sql): array
    {
        $sql = preg_replace('~/\*.*?\*/|--[^\r\n]*|\#[^\r\n]*~s', ' ', $sql) ?? '';
        $pattern = <<<'REGEX'
~'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"~s
REGEX;
        $safe = preg_replace($pattern, '?', $sql) ?? '';
        // Malformed failed SQL may contain an unterminated literal. Redact the entire remaining suffix.
        $safe = preg_replace('/[\'"].*/s', '?', $safe) ?? '';
        $safe = preg_replace('/\b(?:0x[0-9a-f]+|\d+(?:\.\d+)?(?:e[+-]?\d+)?)\b/i', '?', $safe) ?? '';
        $safe = preg_replace('/\?(?:\s*,\s*\?)+/', '?…', $safe) ?? '';
        $safe = strtolower(trim(preg_replace('/\s+/', ' ', $safe) ?? ''));

        return self::classify($safe) + ['id' => hash('sha256', $safe), 'sql' => mb_substr($safe, 0, 500)];
    }

    public static function classify(string $sql): array
    {
        $type = strtolower(strtok(ltrim($sql), ' ') ?: 'other');
        $type = in_array($type, ['select', 'show', 'explain', 'insert', 'update', 'delete', 'replace'], true) ? $type : 'other';
        $read = in_array($type, ['select', 'show', 'explain'], true);
        $category = match (true) {
            stripos($sql, 'information_schema') !== false => 'metadata',
            preg_match('/\b(?:sessions|cache|cache_locks)\b/i', $sql) === 1 => 'session_cache',
            in_array($type, ['insert', 'update', 'delete', 'replace'], true) => 'write',
            $read => 'read',
            default => 'other',
        };

        return ['type' => $type, 'category' => $category, 'read' => $read,
            'locking' => preg_match('/\bfor update\b|\block in share mode\b/i', $sql) === 1];
    }

    public static function caller(): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');
            if (str_starts_with($file, $base.'app/') && ! preg_match('/Performance|RecordRequestPerformance/', $file)) {
                return substr($file, strlen($base)).':'.(int) ($frame['line'] ?? 0);
            }
        }

        return '呼出元未取得';
    }
}
