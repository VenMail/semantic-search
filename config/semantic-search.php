<?php

return [
    'analysis' => [
        'auto_discover' => true,
        'scan_directories' => [
            'controllers' => app_path('Http/Controllers'),
            'models' => app_path('Models'),
            'views' => resource_path('views'),
            'routes' => base_path('routes'),
        ],
        'exclude_patterns' => [
            '*/vendor/*',
            '*/storage/*',
            '*/bootstrap/cache/*',
        ],
    ],

    'cache' => [
        'enabled' => env('SEMANTIC_SEARCH_CACHE_ENABLED', true),
        'default_ttl' => env('SEMANTIC_SEARCH_CACHE_TTL', 3600),
        'prefix' => env('SEMANTIC_SEARCH_CACHE_PREFIX', 'semantic_search'),
    ],

    'performance' => [
        'max_query_time' => env('SEMANTIC_SEARCH_MAX_QUERY_TIME', 30),
        'memory_limit' => env('SEMANTIC_SEARCH_MEMORY_LIMIT', '256M'),
        'enable_query_optimization' => env('SEMANTIC_SEARCH_ENABLE_OPTIMIZATION', true),
        'chunk_size' => env('SEMANTIC_SEARCH_CHUNK_SIZE', 1000),
    ],

    'llm' => [
        'enabled' => env('SEMANTIC_SEARCH_LLM_ENABLED', false),
        'provider' => env('SEMANTIC_SEARCH_LLM_PROVIDER', 'openai'),
        'model' => env('SEMANTIC_SEARCH_LLM_MODEL', 'gpt-3.5-turbo'),
        'api_key' => env('OPENAI_API_KEY'),
        'timeout' => env('SEMANTIC_SEARCH_LLM_TIMEOUT', 10),
        'confidence_threshold' => env('SEMANTIC_SEARCH_LLM_CONFIDENCE', 0.7),
    ],

    'security' => [
        'max_rows' => env('SEMANTIC_SEARCH_MAX_ROWS', 10000),
        'restricted_models' => env('SEMANTIC_SEARCH_RESTRICTED_MODELS', '') ? explode(',', env('SEMANTIC_SEARCH_RESTRICTED_MODELS')) : [],
        'restricted_fields' => env('SEMANTIC_SEARCH_RESTRICTED_FIELDS', '') ? explode(',', env('SEMANTIC_SEARCH_RESTRICTED_FIELDS')) : [],
    ],

    'disambiguation' => [
        'enabled' => env('SEMANTIC_SEARCH_DISAMBIGUATION_ENABLED', true),
        'confidence_threshold' => env('SEMANTIC_SEARCH_DISAMBIGUATION_CONFIDENCE', 0.6),
        'spelling_correction' => [
            'enabled' => true,
            'max_distance' => env('SEMANTIC_SEARCH_SPELLING_MAX_DISTANCE', 2),
            'min_confidence' => env('SEMANTIC_SEARCH_SPELLING_MIN_CONFIDENCE', 0.6),
        ],
    ],

    'history' => [
        'enabled' => env('SEMANTIC_SEARCH_HISTORY_ENABLED', true),
        'max_entries' => env('SEMANTIC_SEARCH_HISTORY_MAX_ENTRIES', 50),
        'ttl_days' => env('SEMANTIC_SEARCH_HISTORY_TTL_DAYS', 30),
    ],
];
