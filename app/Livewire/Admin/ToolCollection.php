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
                    'description' => 'キャラクターや素材画像を分割し、高品質補間・ロスレスWebPで複数キャラの4差分を保存できます。',
                    'href' => asset('tools/sprite-splitter.html'),
                    'badge' => 'PUBLIC',
                    'openLabel' => '開く',
                ],
                [
                    'name' => 'アイコン背景透過ツール',
                    'description' => '消したい背景をクリックし、許容範囲や境界を調整しながらきれいに透過します。',
                    'href' => route('admin.tools.remover'),
                    'badge' => 'PUBLIC',
                    'openLabel' => '開く',
                ],
            ],
        ])->layout('components.layouts.admin');
    }
}
