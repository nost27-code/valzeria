<?php

namespace App\Livewire\Admin;

use Livewire\Component;

class ToolCollection extends Component
{
    public function render()
    {
        return view('livewire.admin.tool-collection', [
            'tools' => [
                [
                    'name' => 'スプライト分割ツール',
                    'description' => 'キャラクターや素材画像のスプライトを分割して確認します。',
                    'href' => asset('tools/sprite-splitter.html'),
                    'badge' => 'PUBLIC',
                    'openLabel' => '開く',
                ],
                [
                    'name' => '背景除去・リサイズツール',
                    'description' => '画像の背景除去、リサイズ、形式変換をブラウザ上で行います。',
                    'href' => url('admin/tools/remover.html'),
                    'badge' => 'PUBLIC',
                    'openLabel' => '開く',
                ],
            ],
        ])->layout('components.layouts.admin');
    }
}
