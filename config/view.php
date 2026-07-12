<?php

return [
    'paths' => [
        resource_path('views'),
    ],

    // Compiled Blade views must not share a path across atomic releases.
    // The public entry point resolves the active release through the stable
    // current symlink, so a shared path can otherwise retain an older template.
    'compiled' => base_path('bootstrap/cache/views'),
];
