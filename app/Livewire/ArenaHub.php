<?php

namespace App\Livewire;

use App\Support\SixHeroCompetitionRules;
use Livewire\Component;

final class ArenaHub extends Component
{
    public const MODE_SIX_HEROES = 'six_heroes';

    public const MODE_LEGACY = 'legacy';

    public string $mode = self::MODE_LEGACY;

    public function mount(): void
    {
        if (! $this->sixHeroesEnabled()) {
            $this->mode = self::MODE_LEGACY;

            return;
        }

        $rememberedMode = session('colosseum_mode');
        $this->mode = in_array($rememberedMode, $this->availableModes(), true)
            ? $rememberedMode
            : self::MODE_SIX_HEROES;
    }

    public function selectMode(string $mode): void
    {
        if (! in_array($mode, $this->availableModes(), true)) {
            return;
        }

        $this->mode = $mode;
        session(['colosseum_mode' => $mode]);
    }

    public function render()
    {
        return view('livewire.arena-hub', [
            'sixHeroesEnabled' => $this->sixHeroesEnabled(),
            'legacyArenaAvailable' => $this->legacyArenaAvailable(),
        ]);
    }

    /** @return array<int, string> */
    private function availableModes(): array
    {
        if (! $this->sixHeroesEnabled()) {
            return [self::MODE_LEGACY];
        }

        return $this->legacyArenaAvailable()
            ? [self::MODE_SIX_HEROES, self::MODE_LEGACY]
            : [self::MODE_SIX_HEROES];
    }

    private function sixHeroesEnabled(): bool
    {
        return (bool) config('features.six_hero_ui_enabled', false);
    }

    private function legacyArenaAvailable(): bool
    {
        return SixHeroCompetitionRules::legacyArenaAvailable();
    }
}
