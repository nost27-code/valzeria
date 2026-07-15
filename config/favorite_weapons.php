<?php

return [
    // Set FAVORITE_WEAPONS_ENABLED=false to hide both the profile setting and card section immediately.
    'enabled' => (bool) env('FAVORITE_WEAPONS_ENABLED', true),
    'max_count' => 3,
];
