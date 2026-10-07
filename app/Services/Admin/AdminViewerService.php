<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminViewerService
{
    public function catalog(): array
    {
        $available = array_column(Schema::getTables(), 'name');
        return array_intersect_key(config('admin_viewer.tables', []), array_flip($available));
    }

    public function browse(string $table, array $filters): array
    {
        $catalog = $this->catalog();
        abort_unless(isset($catalog[$table]), 404);
        $columns = array_values(array_filter(Schema::getColumnListing($table), fn (string $column): bool => !$this->secret($column)));
        abort_if($columns === [], 404);
        $field = $filters['field'] ?? '';
        abort_if($field !== '' && !in_array($field, $columns, true), 422);
        $query = DB::table($table)->select($columns);
        if ($field !== '' && ($filters['value'] ?? '') !== '') {
            $query->where($field, '=', $filters['value']);
        }
        $order = in_array('id', $columns, true) ? 'id' : $columns[0];
        // No model hydration, lifecycle hooks, count(*) or unrelated page queries.
        $rows = $query->orderByDesc($order)->simplePaginate(25)->withQueryString();
        $rows->setCollection($rows->getCollection()->map(fn ($row): array => $this->sanitize((array) $row)));
        return ['table' => $table, 'label' => $catalog[$table], 'columns' => $columns, 'rows' => $rows, 'filters' => $filters];
    }

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->secret($key)) {
                    continue;
                }
                $result[$key] = $this->sanitize($item);
            }
            return $result;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->sanitize($decoded);
            }
            // Reject non-text payloads instead of rendering binary credentials.
            return mb_check_encoding($value, 'UTF-8') ? $value : '[バイナリデータ]';
        }
        return $value;
    }

    private function secret(string $key): bool
    {
        return (bool) preg_match('/password|token|secret|authorization|cookie|credential|private_key|api_key|client_secret|auth_key|p256dh/i', $key);
    }
}
