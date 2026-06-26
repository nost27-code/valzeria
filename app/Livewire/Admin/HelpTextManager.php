<?php

namespace App\Livewire\Admin;

use App\Models\GameText;
use App\Services\GameTextService;
use App\Services\HelpContentService;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class HelpTextManager extends Component
{
    public string $instruction = '';
    public string $footer = '';
    public array $sections = [];

    private array $defaults = [];

    public function mount(HelpContentService $contentService): void
    {
        $this->defaults = $contentService->defaults();
        $content = $contentService->content(editable: true);

        $this->instruction = $content['instruction'];
        $this->footer = $content['footer'];
        $this->sections = $content['sections'];
    }

    public function save(HelpContentService $contentService, GameTextService $gameTextService): void
    {
        if (! Schema::hasTable('game_texts')) {
            session()->flash('status', 'game_texts テーブルが未作成のため保存できません。マイグレーションを実行してください。');
            return;
        }

        $this->defaults = $contentService->defaults();

        $this->saveText(
            $gameTextService,
            HelpContentService::PREFIX . '.instruction',
            $this->instruction,
            $this->defaults['instruction'],
            'ヘルプ/案内所の案内文'
        );
        $this->saveText(
            $gameTextService,
            HelpContentService::PREFIX . '.footer',
            $this->footer,
            $this->defaults['footer'],
            'ヘルプ/案内所の補足文'
        );

        $defaultSections = collect($this->defaults['sections'])->keyBy('slug');
        foreach ($this->sections as $section) {
            $slug = $section['slug'] ?? '';
            if ($slug === '' || ! $defaultSections->has($slug)) {
                continue;
            }

            $default = $defaultSections->get($slug);
            $prefix = HelpContentService::PREFIX . ".sections.{$slug}";
            $label = $default['title'];

            $this->saveText($gameTextService, "{$prefix}.title", $section['title'] ?? '', $default['title'], "{$label} の見出し");
            $this->saveText($gameTextService, "{$prefix}.icon_image", $section['icon_image'] ?? '', $default['icon_image'], "{$label} のアイコン画像パス");
            $this->saveText($gameTextService, "{$prefix}.body", $section['body'] ?? '', $default['body'], "{$label} の本文");
        }

        $this->mount($contentService);
        session()->flash('status', 'ヘルプ/案内所の文言を保存しました。');
    }

    private function saveText(GameTextService $service, string $key, string $value, string $default, string $description): void
    {
        $value = trim($value);

        if ($value === '' || $value === trim($default)) {
            GameText::where('key', $key)->delete();
            $service->forget($key);
            return;
        }

        $service->set($key, $value, $description);
    }

    public function render()
    {
        return view('livewire.admin.help-text-manager')->layout('components.layouts.admin');
    }
}
