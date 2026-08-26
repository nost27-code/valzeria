<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Support\Facades\DB;

final class NationDecorationService
{
    public function __construct(
        private readonly NationDecorationCatalog $catalog,
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationLevelBenefitSettingsService $settings,
    ) {}

    /** @param array<string, string|null> $settings */
    public function save(NationMembership $actor, array $settings): Nation
    {
        $this->settings->assertEnabled();

        return DB::transaction(function () use ($actor, $settings): Nation {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = NationMembership::whereKey($actor->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();
            throw_unless($lockedActor, \DomainException::class, '国家の所属情報が変更されました。');
            $this->roles->authorize($lockedActor, 'manage_decorations');
            $level = $this->levels->levelFor((int) $nation->development_exp);
            $normalized = [];
            foreach (NationDecorationCatalog::TYPES as $type) {
                $key = trim((string) ($settings[$type] ?? ''));
                if ($key === '') {
                    $normalized[$type] = null;

                    continue;
                }
                $item = $this->catalog->get($key);
                throw_unless($item && $item['type'] === $type, \DomainException::class, '選択した国家装飾は使用できません。');
                throw_if($level < $item['required_level'], \DomainException::class, "この装飾は国家Lv{$item['required_level']}で開放されます。");
                $normalized[$type] = $key;
            }

            $nation->update(['decoration_settings' => $normalized]);
            $this->activityLogs->record($nation, 'nation_decorations_changed', $lockedActor->character, null, [
                'decoration_settings' => $normalized,
            ]);

            return $nation->fresh();
        }, 3);
    }

    /** @return array<string, array{key:string,name:string,css_class:string}> */
    public function presentation(Nation $nation): array
    {
        $settings = is_array($nation->decoration_settings) ? $nation->decoration_settings : [];
        $level = $this->levels->levelFor((int) $nation->development_exp);
        $resolved = [];
        foreach (NationDecorationCatalog::TYPES as $type) {
            $key = (string) ($settings[$type] ?? '');
            $item = $this->catalog->get($key);
            if (! $item || $item['type'] !== $type || $level < $item['required_level']) {
                continue;
            }
            $resolved[$type] = [
                'key' => $key,
                'name' => $item['name'],
                'css_class' => $item['css_class'],
            ];
        }

        return $resolved;
    }
}
