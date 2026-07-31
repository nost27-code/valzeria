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
        'sort_order' => 0,
        'is_active' => true,
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
            'sort_order' => (int) $update->sort_order,
            'is_active' => (bool) $update->is_active,
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.published_on' => ['required', 'date'],
            'form.body' => ['required', 'string', 'max:255'],
            'form.detail' => ['nullable', 'string', 'max:2000'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'form.is_active' => ['boolean'],
        ])['form'];

        $payload = [
            'published_on' => $validated['published_on'],
            'body' => $validated['body'],
            'detail' => trim((string) ($validated['detail'] ?? '')) ?: null,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) $validated['is_active'],
            'is_dismissed' => false,
        ];

        if ($this->editingId) {
            TopUpdate::findOrFail($this->editingId)->update($payload);
        } else {
            TopUpdate::create($payload);
        }

        app(TownUpdateService::class)->forgetPublishedCache();
        session()->flash('status', $this->editingId ? '更新情報を保存しました。' : '更新情報を追加しました。');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $update = TopUpdate::findOrFail($id);
        if ($update->is_dismissed) {
            return;
        }

        $update->forceFill(['is_active' => !$update->is_active])->save();
        app(TownUpdateService::class)->forgetPublishedCache();
    }

    public function delete(int $id): void
    {
        $update = TopUpdate::findOrFail($id);
        if ($update->source_key) {
            $update->forceFill([
                'is_active' => false,
                'is_dismissed' => true,
            ])->save();
            $message = '自動生成された更新候補を掲載対象から除外しました。';
        } else {
            $update->delete();
            $message = '更新情報を削除しました。';
        }

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        app(TownUpdateService::class)->forgetPublishedCache();
        session()->flash('status', $message);
    }

    public function restore(int $id): void
    {
        TopUpdate::query()
            ->whereKey($id)
            ->whereNotNull('source_key')
            ->update([
                'is_active' => false,
                'is_dismissed' => false,
            ]);

        session()->flash('status', '更新候補を下書きへ戻しました。');
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'published_on' => today('Asia/Tokyo')->toDateString(),
            'body' => '',
            'detail' => '',
            'sort_order' => ((int) TopUpdate::max('sort_order')) + 10,
            'is_active' => false,
        ];
    }

    public function render()
    {
        $updates = TopUpdate::query()
            ->orderByDesc('published_on')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return view('livewire.admin.top-update-manager', [
            'updates' => $updates,
        ])->layout('components.layouts.admin');
    }
}
