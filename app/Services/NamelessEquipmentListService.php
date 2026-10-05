<?php

namespace App\Services;

use App\Models\PlayerNamelessEquipment;
use Illuminate\Support\Collection;

class NamelessEquipmentListService
{
    public const DEFAULTS = [
        'gear_query' => '', 'gear_kind' => '', 'gear_type' => '', 'gear_state' => '',
        'gear_protection' => '', 'gear_source' => '', 'gear_sort' => 'equipped',
    ];

    public function options(): array
    {
        $types = array_merge(
            array_keys(NamelessEquipmentService::statOptionsFor('weapon')),
            array_keys(NamelessEquipmentService::statOptionsFor('armor')),
            array_keys(NamelessEquipmentService::statOptionsFor('accessory')),
        );

        return [
            'gear_kind' => ['' => 'すべて', 'weapon' => '武器', 'armor' => '防具', 'accessory' => '装飾品'],
            'gear_type' => ['' => 'すべて'] + array_combine($types, $types),
            'gear_state' => ['' => 'すべて', 'equipped' => '装備中', 'reserve' => '控え'],
            'gear_protection' => ['' => 'すべて', 'locked' => '保護中', 'unlocked' => '未保護'],
            'gear_source' => ['' => 'すべて', 'starter' => '初期配布', 'ruin' => '遺跡で発見'],
            'gear_sort' => [
                'equipped' => '装備中を先に', 'newest' => '入手が新しい順', 'oldest' => '入手が古い順',
                'forge_desc' => '強化段階が高い順', 'forge_asc' => '強化段階が低い順',
                'exp_desc' => '成長EXPが多い順', 'name' => '名前順',
            ],
        ];
    }

    public function normalize(array $input): array
    {
        $filters = self::DEFAULTS;
        $options = $this->options();
        foreach ($filters as $key => $default) {
            $value = $input[$key] ?? $default;
            if (! is_string($value)) {
                continue;
            }
            if ($key === 'gear_query') {
                $filters[$key] = mb_substr(trim($value), 0, 64);
            } elseif (array_key_exists($value, $options[$key])) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    public function query(array $filters): array
    {
        return array_filter($filters, fn ($value, $key) => $value !== self::DEFAULTS[$key], ARRAY_FILTER_USE_BOTH);
    }

    public function filter(Collection $equipment, array $filters): Collection
    {
        $matches = $equipment->filter(function (PlayerNamelessEquipment $body) use ($filters) {
            return ($filters['gear_query'] === '' || mb_stripos($body->displayName().' '.$body->equipment_type.' #'.$body->id, $filters['gear_query']) !== false)
                && ($filters['gear_kind'] === '' || $body->kind === $filters['gear_kind'])
                && ($filters['gear_type'] === '' || $body->equipment_type === $filters['gear_type'])
                && ($filters['gear_state'] === '' || $body->is_equipped === ($filters['gear_state'] === 'equipped'))
                && ($filters['gear_protection'] === '' || $body->is_locked === ($filters['gear_protection'] === 'locked'))
                && ($filters['gear_source'] === '' || $body->acquisition_source === $filters['gear_source']);
        });

        return $matches->sort(function (PlayerNamelessEquipment $a, PlayerNamelessEquipment $b) use ($filters) {
            $order = match ($filters['gear_sort']) {
                'newest' => $b->id <=> $a->id,
                'oldest' => $a->id <=> $b->id,
                'forge_desc' => $b->forge_level <=> $a->forge_level,
                'forge_asc' => $a->forge_level <=> $b->forge_level,
                'exp_desc' => $b->growth_exp <=> $a->growth_exp,
                'name' => mb_strtolower($a->displayName()) <=> mb_strtolower($b->displayName()),
                default => $b->is_equipped <=> $a->is_equipped,
            };

            return $order ?: $a->id <=> $b->id;
        })->keyBy('id');
    }
}
