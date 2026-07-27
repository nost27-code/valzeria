<?php

namespace App\Services\Admin;

use App\Models\Character;
use App\Support\CharacterIconCatalog;

class CharacterIconUsageService
{
    private const TESTER_EMAIL_PATTERN = 'tester_%@valzeria.local';

    public function summary(): array
    {
        $paths = CharacterIconCatalog::paths();
        $selectablePaths = array_fill_keys($paths, true);
        $counts = array_fill_keys($paths, 0);
        $totalCharacters = 0;
        $unrecognizedCharacters = 0;

        $groupedCounts = Character::query()
            ->join('users', 'characters.user_id', '=', 'users.id')
            ->where(function ($query): void {
                $query->whereNull('users.role')
                    ->orWhere('users.role', '!=', 'admin');
            })
            ->where('users.email', 'not like', self::TESTER_EMAIL_PATTERN)
            ->select('characters.icon_path')
            ->selectRaw('COUNT(*) as usage_count')
            ->groupBy('characters.icon_path')
            ->get();

        foreach ($groupedCounts as $groupedCount) {
            $usageCount = (int) $groupedCount->usage_count;
            $totalCharacters += $usageCount;
            $path = $this->selectablePath($groupedCount->icon_path, $selectablePaths);
            if ($path === null) {
                $unrecognizedCharacters += $usageCount;

                continue;
            }

            $counts[$path] += $usageCount;
        }

        $rows = collect($paths)
            ->map(function (string $path) use ($counts, $totalCharacters): array {
                $count = (int) ($counts[$path] ?? 0);

                return [
                    'path' => $path,
                    'number' => $this->numberFromPath($path),
                    'count' => $count,
                    'percent' => $totalCharacters > 0
                        ? round($count / $totalCharacters * 100, 1)
                        : 0.0,
                    'is_used' => $count > 0,
                ];
            })
            ->values()
            ->all();

        $usedIconCount = count(array_filter($rows, fn (array $row): bool => $row['is_used']));

        return [
            'total_characters' => $totalCharacters,
            'selectable_icon_count' => count($rows),
            'used_icon_count' => $usedIconCount,
            'unused_icon_count' => count($rows) - $usedIconCount,
            'unrecognized_character_count' => $unrecognizedCharacters,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, bool>  $selectablePaths
     */
    private function selectablePath(?string $path, array $selectablePaths): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return CharacterIconCatalog::DEFAULT_ICON;
        }

        $path = '/'.ltrim($path, '/');
        if (preg_match('/\A\/images\/chara\/chara_(\d{1,3})\.webp\z/', $path, $matches) !== 1) {
            return null;
        }

        $normalized = sprintf('/images/chara/chara_%03d.webp', (int) $matches[1]);

        return isset($selectablePaths[$normalized]) ? $normalized : null;
    }

    private function numberFromPath(string $path): int
    {
        if (preg_match('/chara_(\d{3})\.webp\z/', $path, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
