<?php

return [
    'output_path' => public_path('docs/flow'),

    'app_dir' => app_path(),

    'migration_dirs' => [
        database_path('migrations'),
    ],

    'project_name' => config('app.name', 'Laravel'),

    'back_link' => null,

    'controller_namespaces' => [
        'App\\Http\\Controllers',
    ],

    'service_namespaces' => [
        'App\\Services',
        'App\\Http\\Services',
        'App\\Actions',
        'App\\Domain',
    ],

    'model_namespaces' => [
        'App\\Models',
        'App',
    ],

    'ignored_model_names' => [
        'DB', 'Log', 'Auth', 'Hash', 'Mail', 'Storage', 'Excel', 'Carbon', 'DataTables',
        'Request', 'Response', 'Controller', 'Exception', 'DateTime', 'Str', 'PDF',
        'string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'array', 'object',
        'mixed', 'void', 'null', 'false', 'true', 'Collection', 'LengthAwarePaginator',
        'Builder', 'QueryBuilder', 'JsonResponse', 'RedirectResponse', 'View',
    ],

    'business_terms' => [
        'gateway' => ['gateway', 'Gateway', 'payment', 'pagamento', 'Pagamento'],
        'payload' => ['payload', 'Payload', 'body', 'requestBody'],
    ],

    'named_gateways' => [
        'Lytex',
        'PagarMe',
        'Stripe',
        'PayPal',
    ],
];
