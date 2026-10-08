<?php

namespace App\Services;

use Throwable;

final class RequestPerformanceStore
{
    public function write(array $profile): void
    {
        try {
            $directory = $this->directory();
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return;
            }
            $this->prune();
            $bucket = gmdate('YmdHi', $profile['time']);
            $path = $directory.'/'.$bucket.'.jsonl';
            $stream = @fopen($path, 'c+b');
            if (! $stream) {
                $this->gap($bucket, 'storage');

                return;
            }
            try {
                if (! flock($stream, LOCK_EX | LOCK_NB)) {
                    $this->gap($bucket, 'busy');

                    return;
                }
                $line = json_encode($profile, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
                $size = fstat($stream)['size'];
                $limit = (int) config('request_performance.max_bucket_bytes', 131072);
                if ($size + strlen($line) > $limit && $profile['query_details'] !== []) {
                    $profile['query_details'] = [];
                    $profile['detail_truncated'] = true;
                    $line = json_encode($profile, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
                }
                if ($size + strlen($line) > $limit) {
                    $this->gap($bucket, 'capacity');

                    return;
                }
                fseek($stream, 0, SEEK_END);
                if (fwrite($stream, $line) !== strlen($line)) {
                    ftruncate($stream, $size);
                    $this->gap($bucket, 'storage');
                }
                @chmod($path, 0600);
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            // No DB/cache/logger fallback; recording failures cannot change a game result.
        }
    }

    public function read(int $from, int $to): array
    {
        $rows = [];
        $warnings = [];
        $bytes = 0;
        $files = glob($this->directory().'/*.jsonl') ?: [];
        rsort($files);
        $fromBucket = gmdate('YmdHi', $from);
        $toBucket = gmdate('YmdHi', $to);
        foreach ($files as $path) {
            $bucket = basename($path, '.jsonl');
            if (! preg_match('/\A\d{12}\z/', $bucket) || $bucket < $fromBucket || $bucket > $toBucket) {
                continue;
            }
            $stream = @fopen($path, 'rb');
            if (! $stream || ! flock($stream, LOCK_SH | LOCK_NB)) {
                if ($stream) {
                    fclose($stream);
                }
                $warnings[] = '読み取り中の保存競合・障害で一部の記録を取得できませんでした。';

                continue;
            }
            try {
                while (($line = fgets($stream, 32768)) !== false) {
                    $bytes += strlen($line);
                    if ($bytes > (int) config('request_performance.max_read_bytes', 8388608)
                        || count($rows) >= (int) config('request_performance.max_read_records', 20000)) {
                        $warnings[] = '読み込み上限に達しました。期間を短くして確認してください。';
                        break 2;
                    }
                    $row = json_decode($line, true);
                    if (! $this->valid($row)) {
                        $warnings[] = '破損した記録を除外しました。';

                        continue;
                    }
                    if ($row['time'] >= $from && $row['time'] < $to) {
                        $rows[] = $row;
                    }
                }
            } finally {
                fclose($stream);
            }
        }
        foreach (glob($this->directory().'/*.gap-*') ?: [] as $path) {
            $bucket = substr(basename($path), 0, 12);
            if ($bucket >= $fromBucket && $bucket <= $toBucket) {
                $warnings[] = '保存上限・保存競合・障害による欠測があります。回数と合計時間は保存できた記録の値です。';
                break;
            }
        }

        return ['rows' => $rows, 'warnings' => array_values(array_unique($warnings))];
    }

    public function directory(): string
    {
        return (string) config('request_performance.path');
    }

    private function valid(mixed $row): bool
    {
        if (! is_array($row) || ! is_string($row['operation'] ?? null) || strlen($row['operation']) > 1000
            || ! is_bool($row['detail_sampled'] ?? null) || ! is_bool($row['detail_truncated'] ?? null)
            || ! is_string($row['release'] ?? null)) {
            return false;
        }
        foreach (['time', 'status', 'queries', 'db_ms', 'response_ms', 'duplicate_reads', 'transaction_ms',
            'max_transaction_ms', 'locking_ms', 'max_sql_ms', 'open_transactions'] as $field) {
            if (! isset($row[$field]) || ! is_numeric($row[$field]) || ! is_finite((float) $row[$field]) || $row[$field] < 0) {
                return false;
            }
        }
        foreach (['categories', 'errors', 'phases', 'query_details'] as $field) {
            if (! is_array($row[$field] ?? null)) {
                return false;
            }
        }
        foreach (['read_queries', 'write_queries'] as $field) {
            if (isset($row[$field]) && (! is_numeric($row[$field]) || $row[$field] < 0)) {
                return false;
            }
        }
        if (isset($row['exploration_processing_mode'])
            && ! in_array($row['exploration_processing_mode'], ['legacy', 'batch_reads'], true)) {
            return false;
        }
        foreach (['categories', 'errors', 'phases'] as $field) {
            foreach ($row[$field] as $value) {
                if (! is_numeric($value) || $value < 0) {
                    return false;
                }
            }
        }
        foreach ($row['query_details'] as $query) {
            if (! is_array($query) || ! preg_match('/\A[0-9a-f]{64}\z/', $query['id'] ?? '')
                || ! is_string($query['sql'] ?? null) || ! is_string($query['caller'] ?? null)
                || ! is_string($query['category'] ?? null)) {
                return false;
            }
            foreach (['count', 'ms', 'max_ms', 'duplicates', 'failed'] as $field) {
                if (! isset($query[$field]) || ! is_numeric($query[$field]) || $query[$field] < 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private function gap(string $bucket, string $reason): void
    {
        @touch($this->directory().'/'.$bucket.'.gap-'.$reason);
    }

    private function prune(): void
    {
        $path = $this->directory().'/.prune';
        if (is_file($path) && time() - (int) @filemtime($path) < 3600) {
            return;
        }
        $stream = @fopen($path, 'c+b');
        if (! $stream) {
            return;
        }
        try {
            if (! flock($stream, LOCK_EX | LOCK_NB)) {
                return;
            }
            $cutoff = gmdate('YmdHi', time() - max(1, (int) config('request_performance.retention_hours', 48)) * 3600);
            foreach (glob($this->directory().'/*') ?: [] as $file) {
                if (preg_match('/\A(\d{12})\.(?:jsonl|gap-(?:busy|capacity|storage))\z/', basename($file), $match)
                    && $match[1] < $cutoff) {
                    @unlink($file);
                }
            }
            @touch($path);
        } finally {
            fclose($stream);
        }
    }
}
