<?php

namespace App\Livewire\Admin;

use App\Models\GameText;
use App\Services\GameTextService;
use Livewire\Component;

class GameTextManager extends Component
{
    public ?int $editingId = null;
    public string $search = '';

    public array $form = [
        'key' => '',
        'value' => '',
        'description' => '',
    ];

    public function createNew(): void
    {
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $text = GameText::findOrFail($id);
        $this->editingId = $text->id;
        $this->form = [
            'key' => $text->key,
            'value' => $text->value,
            'description' => $text->description,
        ];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.key' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9._\-]+$/'],
            'form.value' => ['required', 'string'],
            'form.description' => ['nullable', 'string', 'max:255'],
        ], [
            'form.key.regex' => 'キーは英小文字・数字・ドット・ハイフン・アンダースコアのみ使用できます。',
        ])['form'];

        if ($this->editingId) {
            $text = GameText::findOrFail($this->editingId);
            $text->update($validated);
            app(GameTextService::class)->forget($text->key);
        } else {
            $this->validate([
                'form.key' => ['unique:game_texts,key'],
            ], [
                'form.key.unique' => 'このキーはすでに登録されています。',
            ]);
            GameText::create($validated);
        }

        session()->flash('status', $this->editingId ? '文言を更新しました。' : '文言を追加しました。');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $text = GameText::findOrFail($id);
        app(GameTextService::class)->forget($text->key);
        $text->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }
        session()->flash('status', '文言を削除しました。');
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['key' => '', 'value' => '', 'description' => ''];
    }

    public function render()
    {
        $texts = GameText::query()
            ->when($this->search !== '', fn ($q) => $q
                ->where('key', 'like', '%' . $this->search . '%')
                ->orWhere('description', 'like', '%' . $this->search . '%')
            )
            ->orderBy('key')
            ->get();

        return view('livewire.admin.game-text-manager', [
            'texts' => $texts,
        ])->layout('components.layouts.admin');
    }
}
