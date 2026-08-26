<?php

namespace App\Services\Nation;

final class NationDecorationCatalog
{
    public const TYPES = ['outer_frame', 'name_plate', 'header_ornament', 'emblem_frame', 'level_badge', 'divider'];

    /** @var array<string, array{type:string,name:string,required_level:int,css_class:string}> */
    private const ITEMS = [
        'outer_frame_bronze' => ['type' => 'outer_frame', 'name' => '銅の国家外枠', 'required_level' => 5, 'css_class' => 'nation-decoration-frame-bronze'],
        'outer_frame_silver' => ['type' => 'outer_frame', 'name' => '銀の国家外枠', 'required_level' => 15, 'css_class' => 'nation-decoration-frame-silver'],
        'outer_frame_gold' => ['type' => 'outer_frame', 'name' => '金の国家外枠', 'required_level' => 35, 'css_class' => 'nation-decoration-frame-gold'],
        'name_plate_royal' => ['type' => 'name_plate', 'name' => '王家の国家名プレート', 'required_level' => 45, 'css_class' => 'nation-decoration-name-royal'],
        'name_plate_luminous' => ['type' => 'name_plate', 'name' => '極光の国家名プレート', 'required_level' => 50, 'css_class' => 'nation-decoration-name-luminous'],
        'header_ornament_bronze' => ['type' => 'header_ornament', 'name' => '銅のヘッダ装飾', 'required_level' => 5, 'css_class' => 'nation-decoration-header-bronze'],
        'header_ornament_silver' => ['type' => 'header_ornament', 'name' => '銀のヘッダ装飾', 'required_level' => 15, 'css_class' => 'nation-decoration-header-silver'],
        'header_ornament_gold' => ['type' => 'header_ornament', 'name' => '金のヘッダ装飾', 'required_level' => 35, 'css_class' => 'nation-decoration-header-gold'],
        'emblem_frame_special' => ['type' => 'emblem_frame', 'name' => '誉れの紋章枠', 'required_level' => 45, 'css_class' => 'nation-decoration-emblem-special'],
        'level_badge_max' => ['type' => 'level_badge', 'name' => '最大Lv徽章', 'required_level' => 50, 'css_class' => 'nation-decoration-badge-max'],
        'divider_bronze' => ['type' => 'divider', 'name' => '銅の飾り罫', 'required_level' => 5, 'css_class' => 'nation-decoration-divider-bronze'],
        'divider_gold' => ['type' => 'divider', 'name' => '金の飾り罫', 'required_level' => 35, 'css_class' => 'nation-decoration-divider-gold'],
    ];

    /** @return array<string, array{type:string,name:string,required_level:int,css_class:string}> */
    public function all(): array
    {
        return self::ITEMS;
    }

    /** @return array<string, array{type:string,name:string,required_level:int,css_class:string}> */
    public function forType(string $type): array
    {
        return array_filter(self::ITEMS, static fn (array $item): bool => $item['type'] === $type);
    }

    public function exists(string $key): bool
    {
        return isset(self::ITEMS[$key]);
    }

    /** @return array{type:string,name:string,required_level:int,css_class:string}|null */
    public function get(?string $key): ?array
    {
        return $key === null ? null : (self::ITEMS[$key] ?? null);
    }
}
