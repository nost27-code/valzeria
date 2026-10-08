<?php

return [
    // Keep the established path available until both comparison and rollout checks pass.
    'batch_reads_enabled' => (bool) env('EXPLORATION_BATCH_READS_ENABLED', false),
];
