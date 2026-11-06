<?php

return ['provider' => env('FARMER_AI_PROVIDER', 'deepseek'), 'model' => env('FARMER_AI_MODEL'), 'retention_days' => 30,
    'imd_token' => env('FARMER_IMD_API_TOKEN')];
