<?php

return [
    // Set JOB_MASTER_BADGES_ENABLED=false to hide the master-job badge shelf immediately.
    'enabled' => (bool) env('JOB_MASTER_BADGES_ENABLED', true),

    'tiers' => [
        ['rank' => 'normal', 'label' => '基本職', 'color' => '#64748b'],
        ['rank' => 'middle', 'label' => '中級職', 'color' => '#2563eb'],
        ['rank' => 'advanced', 'label' => '上級職', 'color' => '#7c3aed'],
        ['rank' => 'super', 'label' => '超級職', 'color' => '#c2410c'],
        ['rank' => 'crown', 'label' => '冠位職', 'color' => '#b45309'],
    ],
];
