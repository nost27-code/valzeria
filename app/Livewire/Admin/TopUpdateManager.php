<?php

namespace App\Livewire\Admin;

use App\Models\TopUpdate;
use Livewire\Component;

class TopUpdateManager extends Component
{
    public ?int $editingId = null;

    public array $form = [
        'published_on' => '',
        'body' => '',
        'sort_order' => 0,
        'is_active' => true,
    ];

    public function mount(): void
    {
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
            'sort_order' => (int) $update->sort_order,
            'is_active' => (bool) $update->is_active,
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.published_on' => ['required', 'date'],
            'form.body' => ['required', 'string', 'max:255'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'form.is_active' => ['boolean'],
        ])['form'];

        $payload = [
            'published_on' => $validated['published_on'],
            'body' => $validated['body'],
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) $validated['is_active'],
        ];

        if ($this->editingId) {
            TopUpdate::findOrFail($this->editingId)->update($payload);
        } else {
            TopUpdate::create($payload);
        }

        session()->flash('status', $this->editingId ? '更新情報を保存しました。' : '更新情報を追加しました。');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $update = TopUpdate::findOrFail($id);
        $update->forceFill(['is_active' => !$update->is_active])->save();
    }

    public function delete(int $id): void
    {
        TopUpdate::findOrFail($id)->delete();
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
            'sort_order' => ((int) TopUpdate::max('sort_order')) + 10,
            'is_active' => true,
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
