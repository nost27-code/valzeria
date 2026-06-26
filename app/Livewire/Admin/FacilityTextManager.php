<?php

namespace App\Livewire\Admin;

use App\Services\GameTextService;
use App\Support\FacilityConfig;
use Livewire\Component;

class FacilityTextManager extends Component
{
    public array $townValues   = [];
    public array $simpleValues = [];
    public array $homeValues   = [];

    public function mount(): void
    {
        $this->townValues   = $this->loadSection('town',   FacilityConfig::TOWN_ENTRIES);
        $this->simpleValues = $this->loadSection('simple', FacilityConfig::SIMPLE_ONLY_ENTRIES);
        $this->homeValues   = $this->loadSection('home',   FacilityConfig::HOME_ENTRIES);
    }

    private function loadSection(string $section, array $entries): array
    {
        // キャッシュを経由せず DB を直接参照（キャッシュが '' を保持していても正しいデフォルトを表示するため）
        $keys = [];
        foreach ($entries as $entry) {
            $prefix = "fac.{$section}.{$entry['slug']}";
            $keys[] = "{$prefix}.name";
            $keys[] = "{$prefix}.desc";
            $keys[] = "{$prefix}.icon";
        }
        $dbValues = \App\Models\GameText::whereIn('key', $keys)->pluck('value', 'key');

        $result = [];
        foreach ($entries as $entry) {
            $slug   = $entry['slug'];
            $prefix = "fac.{$section}.{$slug}";
            $result[$slug] = [
                'name' => $dbValues->get("{$prefix}.name") ?? $entry['default_name'],
                'desc' => $dbValues->get("{$prefix}.desc") ?? $entry['default_desc'],
                'icon' => $dbValues->get("{$prefix}.icon") ?? $entry['default_icon'],
            ];
        }
        return $result;
    }

    private function sectionValues(string $section): array
    {
        return match ($section) {
            'town'   => $this->townValues,
            'simple' => $this->simpleValues,
            'home'   => $this->homeValues,
            default  => [],
        };
    }

    public function save(): void
    {
        $service = app(GameTextService::class);

        $sections = [
            'town'   => ['entries' => FacilityConfig::TOWN_ENTRIES,        'values' => $this->townValues],
            'simple' => ['entries' => FacilityConfig::SIMPLE_ONLY_ENTRIES, 'values' => $this->simpleValues],
            'home'   => ['entries' => FacilityConfig::HOME_ENTRIES,        'values' => $this->homeValues],
        ];

        foreach ($sections as $section => ['entries' => $entries, 'values' => $vals]) {
            foreach ($entries as $entry) {
                $slug  = $entry['slug'];
                $label = $entry['label'];
                $prefix = "fac.{$section}.{$slug}";
                $fields = $vals[$slug] ?? [];

                foreach (['name', 'desc', 'icon'] as $field) {
                    $val = trim($fields[$field] ?? '');
                    $key = "{$prefix}.{$field}";
                    if ($val !== '') {
                        $fieldLabel = match ($field) {
                            'name' => 'タイトル',
                            'desc' => '説明',
                            'icon' => 'アイコン画像パス',
                        };
                        $service->set($key, $val, "[{$section}] {$label} の{$fieldLabel}");
                    } else {
                        \App\Models\GameText::where('key', $key)->delete();
                        $service->forget($key);
                    }
                }
            }
        }

        session()->flash('status', '施設テキストを保存しました。');
    }

    public function render()
    {
        $entries = [
            'town'   => FacilityConfig::TOWN_ENTRIES,
            'simple' => FacilityConfig::SIMPLE_ONLY_ENTRIES,
            'home'   => FacilityConfig::HOME_ENTRIES,
        ];

        return view('livewire.admin.facility-text-manager', [
            'entries' => $entries,
        ])->layout('components.layouts.admin');
    }
}
