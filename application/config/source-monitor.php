<?php

return [
    'enabled' => env('SOURCE_MONITOR_ENABLED', false),
    'ca_bundle' => env('SOURCE_MONITOR_CA_BUNDLE', PHP_OS_FAMILY === 'Windows' ? storage_path('app/source-monitor-windows-roots.pem') : null),
    'sources' => [
        'pilibhit-representatives' => 'https://pilibhit.nic.in/constituencies-2/',
        'pilibhit-officers' => 'https://pilibhit.nic.in/about-district/whos-who/',
    ],
];
