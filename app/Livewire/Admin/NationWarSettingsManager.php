<?php

namespace App\Livewire\Admin;

use App\Models\GameSetting;
use App\Models\Nation;
use App\Models\NationMaterialConversionRate;
use App\Models\NationWar;
use App\Services\GameSettingService;
use App\Services\Nation\NationWarLifecycleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class NationWarSettingsManager extends Component
{
    /** @var array<string, string> */
    public array $values = [];
    /** @var array<int, int> */
    public array $materialPoints = [];

    public function boot(): void
    {
        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }

    public function mount(): void { $this->reloadValues(); }

    public function save(GameSettingService $service): void
    {
        foreach ($this->nationSettings() as $setting) {
            $value = trim((string) ($this->values[$setting->id] ?? $setting->value));
            if ($setting->value_type === 'boolean') $value = filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
            elseif (in_array($setting->value_type, ['integer','float'], true) && ! is_numeric($value)) {
                $this->addError('values.'.$setting->id, '数値を入力してください。'); return;
            }
            $service->set($setting->setting_key, $value);
        }
        foreach (NationMaterialConversionRate::all() as $rate) {
            $points = max(1, (int) ($this->materialPoints[$rate->id] ?? $rate->points_per_unit));
            $rate->update(['points_per_unit' => $points]);
        }
        session()->flash('status', '国家戦設定を保存しました。');
        $this->reloadValues();
    }

    public function runLifecycle(NationWarLifecycleService $service): void
    {
        $result = $service->run();
        session()->flash('status', "ライフサイクルを実行しました（開戦{$result['activated']} / 終戦{$result['resolved']} / 再建{$result['rebuilt']}）。");
    }

    public function render()
    {
        return view('livewire.admin.nation-war-settings-manager', [
            'settings' => $this->nationSettings(),
            'rates' => NationMaterialConversionRate::with('material')->orderBy('id')->get(),
            'summary' => ['nations' => Nation::count(), 'live_wars' => NationWar::whereIn('status', NationWar::LIVE_STATUSES)->count()],
            'screenEnabled' => (bool) config('features.nation_screen_enabled', true),
            'featureEnabled' => (bool) config('features.nation_war_enabled', false),
        ])->layout('components.layouts.admin');
    }

    private function reloadValues(): void
    {
        $this->values = $this->nationSettings()->pluck('value', 'id')->map(fn ($value) => (string) $value)->all();
        $this->materialPoints = NationMaterialConversionRate::pluck('points_per_unit', 'id')->map(fn ($value) => (int) $value)->all();
    }

    private function nationSettings()
    {
        return GameSetting::where('setting_key', 'like', 'nation.%')->orWhere('setting_key', 'like', 'nation_war.%')->orderBy('setting_key')->get();
    }
}
