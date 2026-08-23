<?php

namespace App\Livewire;

use App\Models\CharacterMaterial;
use App\Models\Nation;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationWar;
use App\Models\NationWarHistory;
use App\Services\Nation\NationMembershipService;
use App\Services\Nation\NationResourceService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationWarSettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

final class NationScreen extends Component
{
    public string $nationName = '';
    public string $nationDescription = '';
    public ?int $donationMaterialId = null;
    public int $donationQuantity = 1;
    public ?string $feedback = null;
    public ?string $error = null;

    public function boot(): void
    {
        abort_unless(config('features.nation_war_enabled', false), 404);
    }

    public function createNation(NationService $service): void
    {
        $this->resetFeedback();
        $this->validate(['nationName' => 'required|string|max:40', 'nationDescription' => 'nullable|string|max:1000']);
        $this->runAction(fn () => $service->create($this->character(), $this->nationName, $this->nationDescription), '新たな国が興った！');
    }

    public function joinNation(int $nationId, NationMembershipService $service): void
    {
        $this->resetFeedback();
        $this->runAction(fn () => $service->join($this->character(), Nation::findOrFail($nationId)), '国家の一員となった！');
    }

    public function donate(NationResourceService $service): void
    {
        $this->resetFeedback();
        $this->validate(['donationMaterialId' => 'required|integer', 'donationQuantity' => 'required|integer|min:1|max:999999']);
        $transaction = null;
        $this->runAction(function () use ($service, &$transaction): void {
            $transaction = $service->donate($this->character(), (int) $this->donationMaterialId, $this->donationQuantity);
        }, '');
        if ($transaction) $this->feedback = number_format((int) $transaction->points_delta).'pt分の資材を納めた！';
    }

    public function render(NationWarSettingsService $settings)
    {
        $character = $this->character();
        $membership = NationMembership::with(['nation.facilities', 'nation.memberships.character'])->where('character_id', $character->id)->first();
        $rates = collect();
        if ($membership) {
            $rates = NationMaterialConversionRate::with('material')->where('is_active', true)->get()->map(function ($rate) use ($character): array {
                $stock = CharacterMaterial::where('character_id', $character->id)->where('material_id', $rate->material_id)->value('quantity') ?? 0;
                return ['material_id' => $rate->material_id, 'name' => $rate->material?->name ?? '不明な素材', 'points' => $rate->points_per_unit, 'quantity' => (int) $stock];
            })->filter(fn (array $row) => $row['quantity'] > 0)->values();
        }
        $wars = $membership ? NationWar::with(['declaringNation', 'defendingNation', 'winnerNation'])
            ->where(fn ($q) => $q->where('declaring_nation_id', $membership->nation_id)->orWhere('defending_nation_id', $membership->nation_id))
            ->latest('id')->limit(10)->get() : collect();

        return view('livewire.nation-screen', [
            'membership' => $membership, 'nations' => $membership ? collect() : Nation::withCount('memberships')->orderByDesc('prestige')->orderBy('id')->limit(100)->get(),
            'rates' => $rates, 'wars' => $wars,
            'histories' => NationWarHistory::with(['declaringNation', 'defendingNation', 'winnerNation'])->latest('resolved_at')->limit(10)->get(),
            'upgradesEnabled' => $settings->facilityUpgradesEnabled(), 'declarationEnabled' => $settings->declarationEnabled(),
            'calibrated' => $settings->calibrated(),
        ]);
    }

    private function character() { return Auth::user()->currentCharacter(); }
    private function resetFeedback(): void { $this->feedback = null; $this->error = null; }
    private function runAction(callable $action, string $message): void
    {
        try { $action(); if ($message !== '') $this->feedback = $message; }
        catch (Throwable $exception) { if (! $exception instanceof \DomainException) report($exception); $this->error = $exception instanceof \DomainException ? $exception->getMessage() : '処理を完了できませんでした。'; }
    }
}
