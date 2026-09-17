<?php

return [
    'key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_SEO_MODEL', 'gpt-5.4-nano'),
    'providers' => [
        'deepseek' => ['key' => env('DEEPSEEK_API_KEY'), 'model' => env('DEEPSEEK_SEO_MODEL')],
        'gemini' => ['key' => env('GEMINI_API_KEY'), 'model' => env('GEMINI_SEO_MODEL')],
        'claude' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => env('CLAUDE_SEO_MODEL')],
    ],
    'ca_bundle' => env('OPENAI_CA_BUNDLE', PHP_OS_FAMILY === 'Windows' ? storage_path('app/source-monitor-windows-roots.pem') : null),
];
