<?php

namespace App\Livewire\Admin;

use App\Models\TopUpdate;
use App\Services\TownUpdateService;
use Livewire\Component;

class TopUpdateManager extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'published_on' => '',
        'body' => '',
        'detail' => '',
    ];

    public function mount(): void
    {
        $created = app(TownUpdateService::class)->syncDraftsFromAdminSummaries();
        if ($created > 0) {
            session()->flash('status', "{$created}件の更新候補を下書きとして自動作成しました。");
        }

        $this->resetForm();
    }

    public function createNew(): void
    {
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $update = TopUpdate::findOrFail($id);
        $this->editingId = $update->id;
        $this->form = [
            'published_on' => optional($update->published_on)->format('Y-m-d') ?: today('Asia/Tokyo')->toDateString(),
            'body' => $update->body,
            'detail' => (string) ($update->detail ?? ''),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.published_on' => ['required', 'date'],
            'form.body' => ['required', 'string', 'max:255'],
            'form.detail' => ['nullable', 'string', 'max:2000'],
        ])['form'];

        $payload = [
            'published_on' => $validated['published_on'],
            'body' => $validated['body'],
            'detail' => trim((string) ($validated['detail'] ?? '')) ?: null,
            'is_dismissed' => false,
        ];

        if ($this->editingId) {
            TopUpdate::findOrFail($this->editingId)->update($payload);
        } else {
            TopUpdate::create($payload + [
                'sort_order' => ((int) TopUpdate::max('sort_order')) + 10,
                'is_active' => false,
            ]);
        }

        app(TownUpdateService::class)->forgetPublishedCache();
        session()->flash('status', $this->editingId ? '更新情報を保存しました。' : '更新情報を追加しました。');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        app(TownUpdateService::class)->toggleActive($id);
    }

    public function moveActive(int $id, string $direction): void
    {
        app(TownUpdateService::class)->moveActive($id, $direction);
    }

    public function delete(int $id): void
    {
        $update = TopUpdate::findOrFail($id);
        app(TownUpdateService::class)->deleteUpdate($update->id);

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        session()->flash('status', '更新情報を削除しました。');
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'published_on' => today('Asia/Tokyo')->toDateString(),
            'body' => '',
            'detail' => '',
        ];
    }

    public function render()
    {
        $draftUpdates = TopUpdate::query()
            ->where('is_active', false)
            ->where('is_dismissed', false)
            ->orderByDesc('published_on')
            ->orderByDesc('id')
            ->get();

        return view('livewire.admin.top-update-manager', [
            'activeUpdates' => app(TownUpdateService::class)->activeForAdmin(),
            'draftUpdates' => $draftUpdates,
        ])->layout('components.layouts.admin');
    }
}
