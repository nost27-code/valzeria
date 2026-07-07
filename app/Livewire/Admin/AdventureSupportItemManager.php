<?php

namespace App\Livewire\Admin;

use App\Services\AdventureSupportItemControlService;
use App\Services\SupportPassService;
use Livewire\Component;

class AdventureSupportItemManager extends Component
{
    public array $items = [];
    public array $campaignInputs = [];
    public bool $supportPassEnabled = false;

    public function mount(AdventureSupportItemControlService $controlService, SupportPassService $supportPassService): void
    {
        $this->loadItems($controlService);
        $this->supportPassEnabled = $supportPassService->enabled();
    }

    public function toggle(string $itemKey, AdventureSupportItemControlService $controlService): void
    {
        $items = $controlService->allStatuses();
        if (!array_key_exists($itemKey, $items)) {
            session()->flash('error', '対象の商品が見つかりません。');
            $this->items = $items;

            return;
        }

        $nextEnabled = !((bool) ($items[$itemKey]['enabled'] ?? false));
        $controlService->setEnabled($itemKey, $nextEnabled);
        $this->loadItems($controlService);

        $name = $this->items[$itemKey]['name'] ?? $itemKey;
        session()->flash('status', "{$name}を" . ($nextEnabled ? '販売ON' : '販売OFF') . 'にしました。');
    }

    public function toggleVisibility(string $itemKey, AdventureSupportItemControlService $controlService): void
    {
        $items = $controlService->allStatuses();
        if (!array_key_exists($itemKey, $items)) {
            session()->flash('error', '対象の商品が見つかりません。');
            $this->items = $items;

            return;
        }

        $nextVisible = !((bool) ($items[$itemKey]['visible'] ?? false));
        $controlService->setVisible($itemKey, $nextVisible);
        $this->loadItems($controlService);

        $name = $this->items[$itemKey]['name'] ?? $itemKey;
        session()->flash('status', "{$name}を" . ($nextVisible ? '表示' : '非表示') . 'にしました。');
    }

    public function saveCampaign(string $itemKey, AdventureSupportItemControlService $controlService): void
    {
        $items = $controlService->allStatuses();
        if (!array_key_exists($itemKey, $items)) {
            session()->flash('error', '対象の商品が見つかりません。');
            $this->loadItems($controlService);

            return;
        }

        $input = $this->campaignInputs[$itemKey] ?? [];
        $price = isset($input['price']) && trim((string) $input['price']) !== ''
            ? max(0, (int) $input['price'])
            : null;

        try {
            $controlService->setCampaign(
                $itemKey,
                $price,
                $input['starts_at'] ?? null,
                $input['ends_at'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
            $this->loadItems($controlService);

            return;
        }

        $this->loadItems($controlService);
        $name = $this->items[$itemKey]['name'] ?? $itemKey;
        session()->flash('status', "{$name}のキャンペーン設定を保存しました。");
    }

    public function clearCampaign(string $itemKey, AdventureSupportItemControlService $controlService): void
    {
        $items = $controlService->allStatuses();
        if (!array_key_exists($itemKey, $items)) {
            session()->flash('error', '対象の商品が見つかりません。');
            $this->loadItems($controlService);

            return;
        }

        $controlService->clearCampaign($itemKey);
        $this->loadItems($controlService);

        $name = $this->items[$itemKey]['name'] ?? $itemKey;
        session()->flash('status', "{$name}のキャンペーン設定をクリアしました。");
    }

    public function toggleSupportPass(SupportPassService $supportPassService, AdventureSupportItemControlService $controlService): void
    {
        $nextEnabled = !$supportPassService->enabled();
        $supportPassService->setEnabled($nextEnabled);

        $this->supportPassEnabled = $supportPassService->enabled();
        $this->loadItems($controlService);

        session()->flash('status', '冒険者支援パスを' . ($nextEnabled ? 'ON' : 'OFF') . 'にしました。');
    }

    public function render()
    {
        return view('livewire.admin.adventure-support-item-manager')
            ->layout('components.layouts.admin');
    }

    private function loadItems(AdventureSupportItemControlService $controlService): void
    {
        $this->items = $controlService->allStatuses();
        $this->campaignInputs = collect($this->items)
            ->mapWithKeys(fn (array $item, string $key): array => [
                $key => [
                    'price' => $item['campaign']['price'] ?? '',
                    'starts_at' => $item['campaign']['starts_at_input'] ?? '',
                    'ends_at' => $item['campaign']['ends_at_input'] ?? '',
                ],
            ])
            ->all();
    }
}
