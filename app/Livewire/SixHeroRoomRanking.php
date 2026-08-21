<?php

namespace App\Livewire;

use App\Enums\SixHeroRoomKey;
use App\Models\SixHeroRanking;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

final class SixHeroRoomRanking extends Component
{
    use WithoutUrlPagination;
    use WithPagination;

    private const PER_PAGE = 20;

    #[Locked]
    public int $seasonId;

    #[Locked]
    public string $roomKey;

    #[Locked]
    public int $currentCharacterId;

    public function mount(int $seasonId, string $roomKey): void
    {
        $this->assertPreviewEnabled();

        $room = SixHeroRoomKey::tryFrom($roomKey);
        abort_if($room === null || $seasonId <= 0, 404);

        $character = Auth::user()?->currentCharacter();
        if ($character === null) {
            abort(403);
        }

        $this->seasonId = $seasonId;
        $this->roomKey = $room->value;
        $this->currentCharacterId = (int) $character->id;
    }

    public function render(): View
    {
        $this->assertPreviewEnabled();
        $room = SixHeroRoomKey::from($this->roomKey);
        $rankings = SixHeroRanking::query()
            ->with('character')
            ->where('season_id', $this->seasonId)
            ->where('room_key', $room->value)
            ->orderBy('rank')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'roomPage');

        return view('livewire.six-hero-room-ranking', [
            'rankings' => $rankings,
            'roomLabel' => $room->label(),
        ]);
    }

    private function assertPreviewEnabled(): void
    {
        abort_unless(
            (bool) config('features.six_hero_ui_enabled', false),
            404,
        );
    }
}
