<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\Enemy;
use App\Services\Admin\ValzeriaLabAccess;
use App\Services\Admin\ValzeriaLabReplayService;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

final class ValzeriaLabReplay extends Component
{
    public string $characterSearch = '';

    public string $characterKind = 'all';

    public ?int $selectedCharacterId = null;

    public string $enemySearch = '';

    public ?int $selectedEnemyId = null;

    public string $battleType = 'pve';

    public int $seed = 20_260_902;

    public string $snapshotJson = '';

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var array<string, mixed> */
    public array $result = [];

    public ?string $notice = null;

    public function mount(): void
    {
        ValzeriaLabAccess::ensureAuthorized();
    }

    public function updatedCharacterSearch(): void
    {
        $this->selectedCharacterId = null;
    }

    public function updatedCharacterKind(): void
    {
        $this->selectedCharacterId = null;
    }

    public function updatedEnemySearch(): void
    {
        $this->selectedEnemyId = null;
    }

    public function selectCharacter(int $characterId): void
    {
        $this->guard();
        $this->selectedCharacterId = $characterId;
        $this->clearExecution();
    }

    public function selectEnemy(int $enemyId): void
    {
        $this->guard();
        $this->selectedEnemyId = $enemyId;
        $this->clearExecution();
    }

    public function captureAndRun(ValzeriaLabReplayService $service): void
    {
        $this->guard();
        $validated = $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
            'selectedEnemyId' => ['required', 'integer', 'exists:enemies,id'],
            'battleType' => ['required', 'in:pve,boss'],
            'seed' => ['required', 'integer', 'min:0', 'max:'.ValzeriaLabReplayService::MAX_SEED],
        ], [], [
            'selectedCharacterId' => 'Character',
            'selectedEnemyId' => '敵',
            'battleType' => '戦闘種別',
            'seed' => 'seed',
        ]);

        $character = Character::query()->findOrFail((int) $validated['selectedCharacterId']);
        $enemy = Enemy::query()->findOrFail((int) $validated['selectedEnemyId']);

        try {
            $this->snapshot = $service->capture(
                $character,
                $enemy,
                (string) $validated['battleType'],
                (int) $validated['seed'],
            );
            $this->snapshotJson = $service->encode($this->snapshot);
            $this->result = $service->presentResult($service->executeSnapshot($this->snapshot));
            $this->notice = '匿名スナップショットを作成し、同じ内容から戦闘を実行しました。';
            $this->resetValidation();
        } catch (InvalidArgumentException $exception) {
            $this->addError('snapshotJson', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('snapshotJson', '戦闘再現に失敗しました。アプリケーションログを確認してください。');
        }
    }

    public function loadSnapshot(ValzeriaLabReplayService $service): void
    {
        $this->guard();
        $this->resetValidation();

        try {
            $this->snapshot = $service->decode($this->snapshotJson);
            $this->seed = (int) $this->snapshot['seed'];
            $this->battleType = (string) $this->snapshot['battle_type'];
            $this->result = [];
            $this->notice = 'JSONを検証し、匿名の戦闘開始状態を読み込みました。';
        } catch (InvalidArgumentException $exception) {
            $this->snapshot = [];
            $this->result = [];
            $this->notice = null;
            $this->addError('snapshotJson', $exception->getMessage());
        }
    }

    public function runLoadedSnapshot(ValzeriaLabReplayService $service): void
    {
        $this->guard();
        $this->validate([
            'seed' => ['required', 'integer', 'min:0', 'max:'.ValzeriaLabReplayService::MAX_SEED],
        ]);
        $this->resetValidation('snapshotJson');

        try {
            $snapshot = $service->decode($this->snapshotJson);
            $snapshot['seed'] = $this->seed;
            $this->snapshot = $snapshot;
            $this->snapshotJson = $service->encode($snapshot);
            $this->result = $service->presentResult($service->executeSnapshot($snapshot, $this->seed));
            $this->notice = '読込済み状態を、指定seedで再現しました。';
        } catch (InvalidArgumentException $exception) {
            $this->result = [];
            $this->addError('snapshotJson', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->result = [];
            $this->addError('snapshotJson', '戦闘再現に失敗しました。アプリケーションログを確認してください。');
        }
    }

    public function clearSnapshot(): void
    {
        $this->guard();
        $this->snapshotJson = '';
        $this->snapshot = [];
        $this->result = [];
        $this->notice = null;
        $this->resetValidation();
    }

    public function render()
    {
        $selectedCharacter = $this->selectedCharacterId
            ? Character::query()->with('user')->find($this->selectedCharacterId)
            : null;
        $selectedEnemy = $this->selectedEnemyId
            ? Enemy::query()->with('area.city')->find($this->selectedEnemyId)
            : null;

        return view('livewire.admin.valzeria-lab.replay', [
            'characterCandidates' => $this->characterCandidates(),
            'enemyCandidates' => $this->enemyCandidates(),
            'selectedCharacter' => $selectedCharacter,
            'selectedEnemy' => $selectedEnemy,
        ])
            ->layout('components.layouts.admin');
    }

    private function characterCandidates()
    {
        $search = trim($this->characterSearch);

        return Character::query()
            ->with('user')
            ->when($this->characterKind === 'tester', fn (Builder $query) => $query->adminTesters())
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    private function enemyCandidates()
    {
        $search = trim($this->enemySearch);

        return Enemy::query()
            ->with('area.city')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('id', $search)
                        ->orWhereHas('area', function (Builder $area) use ($search): void {
                            $area->where('name', 'like', "%{$search}%")
                                ->orWhereHas('city', fn (Builder $city) => $city->where('name', 'like', "%{$search}%"));
                        });
                });
            })
            ->orderBy('level')
            ->orderBy('id')
            ->limit(30)
            ->get();
    }

    private function clearExecution(): void
    {
        $this->snapshot = [];
        $this->result = [];
        $this->notice = null;
        $this->resetValidation();
    }

    private function guard(): void
    {
        ValzeriaLabAccess::ensureAuthorized();
    }
}
