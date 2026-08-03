<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterIconDesignRequest;
use App\Models\CharacterIconEntitlement;
use App\Models\User;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CharacterIconSetService
{
    private const ARENA_SHOWCASE_SCENES = ['normal', 'battle', 'victory', 'defeat'];

    private const SCENE_LABELS = [
        'normal' => '通常',
        'battle' => '戦闘',
        'victory' => '勝利・喜び',
        'defeat' => '敗北',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private array $resolvedPathCache = [];

    /**
     * @return array<int, string>
     */
    public function selectablePaths(Character $character): array
    {
        $exclusivePaths = $character->iconEntitlements()
            ->active()
            ->pluck('icon_set_key')
            ->map(fn (string $setKey): ?string => CharacterIconCatalog::pathForSet($setKey, 'normal'))
            ->filter(fn (?string $path): bool => $path !== null && is_file(public_path(ltrim($path, '/'))))
            ->values()
            ->all();

        return array_values(array_unique([
            ...$exclusivePaths,
            ...CharacterIconCatalog::paths(),
        ]));
    }

    public function canSelect(Character $character, ?string $path): bool
    {
        if (CharacterIconCatalog::isSelectable($path)) {
            return true;
        }

        $normalized = CharacterIconCatalog::normalize($path);

        return in_array($normalized, $this->selectablePaths($character), true);
    }

    /**
     * @return array{normal: string, battle: string, victory: string, defeat: string}
     */
    public function resolvedPaths(Character $character): array
    {
        $cacheKey = $character->getKey().':'.(string) $character->icon_path;
        if (isset($this->resolvedPathCache[$cacheKey])) {
            return $this->resolvedPathCache[$cacheKey];
        }

        $normalPath = CharacterIconCatalog::normalize($character->icon_path);
        $setKey = CharacterIconCatalog::setKeyForPath($normalPath);
        if ($setKey === null) {
            return $this->resolvedPathCache[$cacheKey] = $this->resolveExistingScenePaths(
                $normalPath,
                CharacterIconCatalog::pathsForStandardIcon($normalPath),
            );
        }

        if (! $this->ownsSet($character, $setKey)) {
            return $this->resolvedPathCache[$cacheKey] = $this->resolveExistingScenePaths($normalPath);
        }

        return $this->resolvedPathCache[$cacheKey] = $this->resolveExistingScenePaths(
            $normalPath,
            CharacterIconCatalog::pathsForSet($setKey),
        );
    }

    public function pathFor(Character $character, string $scene): string
    {
        $paths = $this->resolvedPaths($character);

        return $paths[$scene] ?? $paths['normal'];
    }

    /**
     * @return array{path: string, scene: string, label: string, has_choices: bool}
     */
    public function arenaShowcase(Character $character): array
    {
        $normalPath = CharacterIconCatalog::normalize($character->icon_path);
        $setKey = CharacterIconCatalog::setKeyForPath($normalPath);
        if ($setKey === null) {
            return $this->defaultArenaShowcase($normalPath);
        }

        $entitlement = $this->activeEntitlementFor($character, $setKey);
        if ($entitlement === null) {
            return $this->defaultArenaShowcase($normalPath);
        }

        $scene = in_array($entitlement->arena_showcase_scene, self::ARENA_SHOWCASE_SCENES, true)
            ? $entitlement->arena_showcase_scene
            : 'normal';
        $path = CharacterIconCatalog::pathForSet($setKey, $scene);
        if ($path === null || ! is_file(public_path(ltrim($path, '/')))) {
            $path = $normalPath;
        }

        return [
            'path' => $path,
            'scene' => $scene,
            'label' => self::SCENE_LABELS[$scene],
            'has_choices' => true,
        ];
    }

    /**
     * @return array{path: string, scene: string, label: string, has_choices: bool}
     */
    public function cycleArenaShowcase(Character $character): array
    {
        $normalPath = CharacterIconCatalog::normalize($character->icon_path);
        $setKey = CharacterIconCatalog::setKeyForPath($normalPath);
        if ($setKey === null) {
            throw new RuntimeException('4ポーズ対応の限定アイコンを選択してください。');
        }

        return DB::transaction(function () use ($character, $setKey): array {
            $entitlement = CharacterIconEntitlement::query()
                ->where('character_id', $character->id)
                ->where('icon_set_key', $setKey)
                ->active()
                ->lockForUpdate()
                ->first();
            if ($entitlement === null) {
                throw new RuntimeException('この限定アイコンの表示ポーズを変更する権限がありません。');
            }

            $currentScene = in_array($entitlement->arena_showcase_scene, self::ARENA_SHOWCASE_SCENES, true)
                ? $entitlement->arena_showcase_scene
                : 'normal';
            $currentIndex = array_search($currentScene, self::ARENA_SHOWCASE_SCENES, true);
            $nextScene = self::ARENA_SHOWCASE_SCENES[
                ($currentIndex + 1) % count(self::ARENA_SHOWCASE_SCENES)
            ];

            $entitlement->forceFill(['arena_showcase_scene' => $nextScene])->save();
            $character->unsetRelation('iconEntitlements');

            return $this->arenaShowcase($character);
        });
    }

    public function grant(
        Character $character,
        string $setKey,
        ?CharacterIconDesignRequest $designRequest = null,
        ?User $grantedBy = null,
    ): CharacterIconEntitlement {
        $paths = CharacterIconCatalog::pathsForSet($setKey);
        if ($paths === null) {
            throw new RuntimeException("限定アイコンセット「{$setKey}」は定義されていません。");
        }

        foreach (CharacterIconCatalog::SCENES as $scene) {
            $path = $paths[$scene] ?? null;
            if ($path === null || ! is_file(public_path(ltrim($path, '/')))) {
                throw new RuntimeException("限定アイコンセット「{$setKey}」の{$scene}画像が見つかりません。");
            }
        }

        if ($designRequest !== null && (int) $designRequest->character_id !== (int) $character->id) {
            throw new RuntimeException('制作依頼と付与先のプレイヤーが一致しません。');
        }

        return DB::transaction(function () use ($character, $setKey, $paths, $designRequest, $grantedBy): CharacterIconEntitlement {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();
            $entitlement = CharacterIconEntitlement::query()
                ->where('icon_set_key', $setKey)
                ->lockForUpdate()
                ->first();

            if ($entitlement !== null && (int) $entitlement->character_id !== (int) $lockedCharacter->id) {
                throw new RuntimeException("限定アイコンセット「{$setKey}」は別のプレイヤーへ付与済みです。");
            }

            if ($entitlement === null) {
                $entitlement = CharacterIconEntitlement::query()->create([
                    'character_id' => $lockedCharacter->id,
                    'character_icon_design_request_id' => $designRequest?->id,
                    'granted_by_user_id' => $grantedBy?->id,
                    'icon_set_key' => $setKey,
                    'arena_showcase_scene' => 'normal',
                    'previous_icon_path' => CharacterIconCatalog::normalize($lockedCharacter->icon_path),
                    'granted_at' => now(),
                ]);
            } else {
                $entitlement->forceFill([
                    'character_icon_design_request_id' => $designRequest?->id ?? $entitlement->character_icon_design_request_id,
                    'granted_by_user_id' => $grantedBy?->id ?? $entitlement->granted_by_user_id,
                    'granted_at' => now(),
                    'revoked_at' => null,
                ])->save();
            }

            $lockedCharacter->forceFill(['icon_path' => $paths['normal']])->save();
            $character->setRawAttributes($lockedCharacter->getAttributes(), true);
            $this->resolvedPathCache = [];

            return $entitlement->fresh();
        });
    }

    private function ownsSet(Character $character, string $setKey): bool
    {
        return $this->activeEntitlementFor($character, $setKey) !== null;
    }

    private function activeEntitlementFor(
        Character $character,
        string $setKey,
    ): ?CharacterIconEntitlement {
        if ($character->relationLoaded('iconEntitlements')) {
            return $character->iconEntitlements
                ->first(fn (CharacterIconEntitlement $entitlement): bool => $entitlement->icon_set_key === $setKey
                    && $entitlement->revoked_at === null);
        }

        return $character->iconEntitlements()
            ->active()
            ->where('icon_set_key', $setKey)
            ->first();
    }

    /**
     * @return array{path: string, scene: string, label: string, has_choices: bool}
     */
    private function defaultArenaShowcase(string $path): array
    {
        return [
            'path' => $path,
            'scene' => 'normal',
            'label' => self::SCENE_LABELS['normal'],
            'has_choices' => false,
        ];
    }

    /**
     * @param  array{normal: string, battle: string, victory: string, defeat: string}|null  $candidatePaths
     * @return array{normal: string, battle: string, victory: string, defeat: string}
     */
    private function resolveExistingScenePaths(string $fallbackPath, ?array $candidatePaths = null): array
    {
        $normalCandidate = $candidatePaths['normal'] ?? null;
        $normalPath = $normalCandidate !== null && is_file(public_path(ltrim($normalCandidate, '/')))
            ? $normalCandidate
            : $fallbackPath;

        $paths = [];
        foreach (CharacterIconCatalog::SCENES as $scene) {
            $candidate = $candidatePaths[$scene] ?? null;
            $paths[$scene] = $candidate !== null && is_file(public_path(ltrim($candidate, '/')))
                ? $candidate
                : $normalPath;
        }

        return $paths;
    }
}
