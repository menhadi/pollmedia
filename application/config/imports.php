<?php

return [
    'official_host_suffixes' => ['gov.in', 'nic.in'],
    'python' => env('IMPORT_PYTHON_BINARY', 'python'),
    'extractor' => base_path('../pilot/extract_import.py'),
    'max_bytes' => 20000000,
    'max_rows' => 20000,
];
