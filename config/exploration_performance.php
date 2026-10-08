<?php

return [
    // Keep the established path available until both comparison and rollout checks pass.
    'batch_reads_enabled' => (bool) env('EXPLORATION_BATCH_READS_ENABLED', false),
    'batch_discoveries_enabled' => (bool) env('EXPLORATION_BATCH_DISCOVERIES_ENABLED', false),
    'batch_state_enabled' => (bool) env('EXPLORATION_BATCH_STATE_ENABLED', false),
];
