<?php

namespace App\Livewire\Admin;

use App\Services\Admin\ValzeriaLabAccess;
use App\Services\Admin\ValzeriaLabReplayService;
use App\Services\Admin\ValzeriaLabVirtualAdventurerService;
use DomainException;
use Livewire\Component;
use Throwable;

final class ValzeriaLabAdventurer extends Component
{
    public string $profile = 'beginner';

    public int $actionLimit = ValzeriaLabVirtualAdventurerService::DEFAULT_ACTIONS;

    public int $seed = 20_260_902;

    /** @var array<string, mixed> */
    public array $result = [];

    public ?string $notice = null;

    public function mount(): void
    {
        ValzeriaLabAccess::ensureAuthorized();
    }

    public function runSimulation(ValzeriaLabVirtualAdventurerService $service): void
    {
        $this->guard();
        $validated = $this->validate([
            'profile' => ['required', 'in:'.implode(',', array_keys(ValzeriaLabVirtualAdventurerService::PROFILES))],
            'actionLimit' => ['required', 'integer', 'min:1', 'max:'.ValzeriaLabVirtualAdventurerService::MAX_ACTIONS],
            'seed' => ['required', 'integer', 'min:0', 'max:'.ValzeriaLabReplayService::MAX_SEED],
        ], [], [
            'profile' => '方針',
            'actionLimit' => '行動上限',
            'seed' => 'seed',
        ]);

        try {
            $this->result = $service->run(
                (string) $validated['profile'],
                (int) $validated['actionLimit'],
                (int) $validated['seed'],
            );
            $this->notice = '仮想冒険者の試行を、永続化せずに完了しました。';
            $this->resetValidation();
        } catch (DomainException $exception) {
            $this->result = [];
            $this->notice = null;
            $this->addError('profile', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->result = [];
            $this->notice = null;
            $this->addError('profile', '仮想試行に失敗しました。アプリケーションログを確認してください。');
        }
    }

    public function clearResult(): void
    {
        $this->guard();
        $this->result = [];
        $this->notice = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->guard();

        return view('livewire.admin.valzeria-lab.adventurer', [
            'profiles' => ValzeriaLabVirtualAdventurerService::PROFILES,
        ])
            ->layout('components.layouts.admin');
    }

    private function guard(): void
    {
        ValzeriaLabAccess::ensureAuthorized();
    }
}
