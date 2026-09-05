<?php

// 国家レイド共通の固定到達報酬（2026-09-04裁定）。新規draftでsnapshot化する。
// 旧イベントのversion=1/選択式snapshotは変更せず、受取互換性を保持する。
// 実装承認は本番公開・Phase 2 balance採用を意味しない。
return [
    'version' => 2,
    'participation_minimum_resolved_sorties' => 5,
    'bottles' => ['participation' => 1, 'stage10' => 3, 'completion' => 2],
    'completion_free_kiseki' => 5,
    'damage_thresholds' => ['damage2m' => 2_000_000],
    'milestones' => [
        ['damage' => 10_000, 'fragment' => 'enhance', 'quantity' => 1, 'free_kiseki' => 3, 'bottles' => 0, 'talismans' => 0],
        ['damage' => 50_000, 'fragment' => 'guard', 'quantity' => 1, 'free_kiseki' => 3, 'bottles' => 0, 'talismans' => 0],
        ['damage' => 100_000, 'fragment' => 'tune', 'quantity' => 1, 'free_kiseki' => 3, 'bottles' => 1, 'talismans' => 0],
        ['damage' => 250_000, 'fragment' => 'enhance', 'quantity' => 2, 'free_kiseki' => 3, 'bottles' => 0, 'talismans' => 1],
        ['damage' => 500_000, 'fragment' => 'guard', 'quantity' => 2, 'free_kiseki' => 3, 'bottles' => 0, 'talismans' => 0],
        ['damage' => 750_000, 'fragment' => 'tune', 'quantity' => 2, 'free_kiseki' => 3, 'bottles' => 0, 'talismans' => 0],
        ['damage' => 1_000_000, 'fragment' => 'enhance', 'quantity' => 3, 'free_kiseki' => 3, 'bottles' => 1, 'talismans' => 1],
        ['damage' => 2_000_000, 'fragment' => 'guard', 'quantity' => 3, 'free_kiseki' => 3, 'bottles' => 0, 'talismans' => 0],
        ['damage' => 5_000_000, 'fragment' => 'tune', 'quantity' => 3, 'free_kiseki' => 3, 'bottles' => 3, 'talismans' => 0],
    ],
    'nation_thresholds' => [250_000 => 1_000, 750_000 => 3_000, 1_500_000 => 6_000],
    'nation_reference_cap' => 1_000,
    'nation_qualified_cap' => 1_500,
];
