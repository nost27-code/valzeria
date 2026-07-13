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
                    'name' => '銘・特攻武器 査定価格算出',
                    'description' => '銘付き・特攻付き武器の良品、逸品を含む市場査定額をすぐに確認します。',
                    'href' => route('admin.tools.weapon-appraisal'),
                    'badge' => 'ADMIN',
                    'openLabel' => '査定する',
                ],
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
