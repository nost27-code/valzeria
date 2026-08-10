<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterWebPushPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class WebPushPreferenceService
{
    /** @var array<int, array<int, string>> */
    private array $enabledKeyCache = [];

    /**
     * @return array<string, array{label: string, items: array<string, array<string, mixed>>}>
     */
    public function catalog(): array
    {
        return (array) config('web_push.notification_types', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->catalog() as $group) {
            foreach ((array) ($group['items'] ?? []) as $key => $option) {
                $options[(string) $key] = (array) $option;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function allowedKeys(): array
    {
        return array_keys($this->options());
    }

    /**
     * @return array<int, string>
     */
    public function defaultKeys(): array
    {
        return array_keys(array_filter(
            $this->options(),
            static fn (array $option): bool => (bool) ($option['default'] ?? false)
        ));
    }

    /**
     * @return array<int, string>
     */
    public function enabledKeys(Character $character): array
    {
        $characterId = (int) $character->getKey();

        if (array_key_exists($characterId, $this->enabledKeyCache)) {
            return $this->enabledKeyCache[$characterId];
        }

        if (! Schema::hasTable('character_web_push_preferences')) {
            return $this->enabledKeyCache[$characterId] = $this->defaultKeys();
        }

        $preference = $character->relationLoaded('webPushPreference')
            ? $character->getRelation('webPushPreference')
            : CharacterWebPushPreference::query()
                ->where('character_id', $characterId)
                ->first();
        $keys = is_array($preference?->enabled_types)
            ? $preference->enabled_types
            : $this->defaultKeys();

        return $this->enabledKeyCache[$characterId] = $this->normalize($keys);
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function save(Character $character, array $keys): CharacterWebPushPreference
    {
        $normalized = $this->normalize($keys);
        $preference = CharacterWebPushPreference::query()->updateOrCreate(
            ['character_id' => $character->getKey()],
            ['enabled_types' => $normalized]
        );
        $this->enabledKeyCache[(int) $character->getKey()] = $normalized;

        return $preference;
    }

    public function isEnabled(Character $character, string $key): bool
    {
        return in_array($key, $this->enabledKeys($character), true);
    }

    public function applyNotificationFilter(Builder $query, Character $character): Builder
    {
        $options = $this->options();
        $enabledKeys = $this->enabledKeys($character);
        $mappedTypes = [];
        $enabledTypes = [];
        $matchesUnmapped = false;

        foreach ($options as $key => $option) {
            $types = array_values(array_filter(array_map('strval', (array) ($option['types'] ?? []))));
            $mappedTypes = [...$mappedTypes, ...$types];

            if (! in_array($key, $enabledKeys, true)) {
                continue;
            }

            $enabledTypes = [...$enabledTypes, ...$types];
            $matchesUnmapped = $matchesUnmapped || (bool) ($option['matches_unmapped'] ?? false);
        }

        $mappedTypes = array_values(array_unique($mappedTypes));
        $enabledTypes = array_values(array_unique($enabledTypes));

        return $query->where(function (Builder $filter) use ($enabledTypes, $mappedTypes, $matchesUnmapped): void {
            if ($enabledTypes !== []) {
                $filter->whereIn('type', $enabledTypes);
            }

            if ($matchesUnmapped) {
                $method = $enabledTypes === [] ? 'whereNotIn' : 'orWhereNotIn';
                $filter->{$method}('type', $mappedTypes);
            }

            if ($enabledTypes === [] && ! $matchesUnmapped) {
                $filter->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function normalize(array $keys): array
    {
        $allowed = array_flip($this->allowedKeys());
        $normalized = [];

        foreach ($keys as $key) {
            if (is_string($key) && isset($allowed[$key]) && ! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }
}
