<?php

namespace App\Services;

use App\Models\PlayerNamelessEquipment;
use App\Support\PlayerStatLabel;

class NamelessEquipmentPowerService
{
    /** 既存APIの単一値は従来の主能力を返す。武具の装備計算にはstatsAtを使う。 */
    public function powerAt(PlayerNamelessEquipment $equipment, int $level): int
    {
        $stats = $this->statsAt($equipment, $level);
        $primary = NamelessEquipmentService::statFor($equipment->kind, $equipment->equipment_type)['key'];

        return $stats[$primary];
    }

    /** @return array<string, int> */
    public function statsAt(PlayerNamelessEquipment $equipment, int $level): array
    {
        $primary = NamelessEquipmentService::statFor($equipment->kind, $equipment->equipment_type)['key'];
        if (! app(NamelessWorkshopService::class)->enabled()) {
            return [$primary => $this->curvePower($equipment, $level, null)];
        }

        $targets = in_array($equipment->kind, NamelessEquipmentService::KINDS, true)
            ? config('nameless_relics.'.$equipment->kind.'_stat_targets_at_max.'.$equipment->equipment_type)
            : null;
        if (is_array($targets)) {
            $stats = [];
            foreach ($targets as $stat => $target) {
                $stats[$stat] = $this->curvePower($equipment, $level, (int) $target);
            }

            return $stats;
        }

        $target = (int) config('nameless_relics.'.$equipment->kind.'_power_at_max', $equipment->kind === 'weapon' ? 12500 : 8000);

        return [$primary => $this->curvePower($equipment, $level, $target)];
    }

    public function labelAt(PlayerNamelessEquipment $equipment, int $level, ?int $nextLevel = null): string
    {
        $current = $this->statsAt($equipment, $level);
        $next = $nextLevel === null ? [] : $this->statsAt($equipment, $nextLevel);
        $labels = [];
        foreach ($current as $stat => $power) {
            $labels[] = PlayerStatLabel::for($stat).' +'.$power.($nextLevel === null ? '' : ' → +'.$next[$stat]);
        }

        return implode(' / ', $labels);
    }

    /** 所有行のbase/stepを保持し、目標までの追加分を同じ二次曲線で配分する。 */
    private function curvePower(PlayerNamelessEquipment $equipment, int $level, ?int $target): int
    {
        $maxLevel = NamelessEquipmentService::MAX_FORGE_LEVEL;
        $level = max(0, min($maxLevel, $level));
        $base = (int) $equipment->base_power;
        $step = (int) $equipment->power_per_level;
        $legacy = $base + $level * $step;
        if ($target === null) {
            return $legacy;
        }
        $extraAtMax = max(0, $target - ($base + $maxLevel * $step));

        return $legacy + intdiv($extraAtMax * $level * $level, $maxLevel * $maxLevel);
    }
}
