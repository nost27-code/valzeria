<?php

namespace App\Livewire\Admin;

use App\Services\ExtraContentControlService;
use App\Services\ReleaseReadinessService;
use Livewire\Component;

class ExtraContentManager extends Component
{
    public array $contents = [];
    public array $periodInputs = [];

    public function mount(ExtraContentControlService $controlService): void
    {
        $this->loadContents($controlService);
    }

    public function toggle(string $contentKey, ExtraContentControlService $controlService, ReleaseReadinessService $readiness): void
    {
        $contents = $controlService->allStatuses();
        if (!array_key_exists($contentKey, $contents)) {
            session()->flash('error', '対象のコンテンツが見つかりません。');
            $this->contents = $contents;

            return;
        }

        $nextEnabled = !((bool) ($contents[$contentKey]['enabled'] ?? false));
        if ($nextEnabled) {
            $issues = $readiness->contentIssues($contentKey);
            if ($issues !== []) {
                session()->flash('error', '必要なマスタが揃っていないためONにできません。' . PHP_EOL . implode(PHP_EOL, $issues));
                $this->contents = $contents;

                return;
            }
        }
        $controlService->setEnabled($contentKey, $nextEnabled);
        $this->loadContents($controlService);

        $name = $this->contents[$contentKey]['name'] ?? $contentKey;
        session()->flash('status', "{$name}を" . ($nextEnabled ? 'ON' : 'OFF') . 'にしました。');
    }

    public function savePeriod(string $contentKey, ExtraContentControlService $controlService, ReleaseReadinessService $readiness): void
    {
        $contents = $controlService->allStatuses();
        if (!array_key_exists($contentKey, $contents)) {
            session()->flash('error', '対象のコンテンツが見つかりません。');
            $this->loadContents($controlService);

            return;
        }

        if ((bool) ($contents[$contentKey]['enabled'] ?? false)) {
            $issues = $readiness->contentIssues($contentKey);
            if ($issues !== []) {
                session()->flash('error', '必要なマスタが揃っていないため開催期間を保存できません。' . PHP_EOL . implode(PHP_EOL, $issues));
                $this->contents = $contents;

                return;
            }
        }

        $input = $this->periodInputs[$contentKey] ?? [];

        try {
            $controlService->setPeriod(
                $contentKey,
                $input['starts_at'] ?? null,
                $input['ends_at'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
            $this->loadContents($controlService);

            return;
        }

        $this->loadContents($controlService);
        $name = $this->contents[$contentKey]['name'] ?? $contentKey;
        session()->flash('status', "{$name}の開催期間を保存しました。");
    }

    public function clearPeriod(string $contentKey, ExtraContentControlService $controlService): void
    {
        $contents = $controlService->allStatuses();
        if (!array_key_exists($contentKey, $contents)) {
            session()->flash('error', '対象のコンテンツが見つかりません。');
            $this->loadContents($controlService);

            return;
        }

        $controlService->clearPeriod($contentKey);
        $this->loadContents($controlService);

        $name = $this->contents[$contentKey]['name'] ?? $contentKey;
        session()->flash('status', "{$name}の開催期間をクリアしました。");
    }

    public function render()
    {
        return view('livewire.admin.extra-content-manager')
            ->layout('components.layouts.admin');
    }

    private function loadContents(ExtraContentControlService $controlService): void
    {
        $this->contents = $controlService->allStatuses();
        $this->periodInputs = collect($this->contents)
            ->mapWithKeys(fn (array $content, string $key): array => [
                $key => [
                    'starts_at' => $content['period']['starts_at_input'] ?? '',
                    'ends_at' => $content['period']['ends_at_input'] ?? '',
                ],
            ])
            ->all();
    }
}
