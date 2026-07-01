<?php

namespace App\Livewire\Admin;

use App\Models\GameSetting;
use App\Services\GameSettingService;
use Livewire\Component;

class RewardSettingManager extends Component
{
    public array $values = [];

    public function mount(): void
    {
        $this->values = GameSetting::query()
            ->orderBy('id')
            ->pluck('value', 'id')
            ->toArray();
    }

    public function save(): void
    {
        $settings = GameSetting::query()->get()->keyBy('setting_key');

        foreach ($settings as $key => $setting) {
            $raw = $this->values[$setting->id] ?? $setting->value;
            $value = $this->normalizeValue((string) $setting->value_type, $raw);
            if ($key === 'exploration.mode') {
                $value = in_array($value, ['cooldown', 'stamina'], true) ? $value : 'cooldown';
            }

            $setting->update(['value' => (string) $value]);
        }

        app(GameSettingService::class)->flush();
        session()->flash('status', '運営設定を保存しました。');
    }

    public function render()
    {
        return view('livewire.admin.reward-setting-manager', [
            'settings' => GameSetting::query()->orderBy('id')->get(),
        ])->layout('components.layouts.admin');
    }

    private function normalizeValue(string $type, mixed $raw): int|float|string
    {
        if ($type === 'boolean') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($type === 'string') {
            return trim((string) $raw);
        }

        $number = is_numeric($raw) ? (float) $raw : 0.0;

        if ($type === 'integer') {
            return max(0, (int) round($number));
        }

        return max(0, round($number, 4));
    }
}
