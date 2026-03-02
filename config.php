<?php
return [
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '172.31.16.0',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),

        // MUST MATCH Hyperf default cache DB
        'cache_db'  => (int) ($_ENV['REDIS_DB'] ?? 1),

        // MUST MATCH Hyperf queue DB
        'queue_db' => (int) ($_ENV['REDIS_QUEUE_DB'] ?? 2),

        'queue' => getenv('REDIS_QUEUE') ?: 'card-image',
    ],
    'db' => [
        'host'     => getenv('DB_HOST') ?: 'aatm-aurora-cluster.cluster-ctiiwqm0m04r.us-west-2.rds.amazonaws.com',
        'database' => getenv('DB_DATABASE') ?: 'aatm_apidb26',
        'username' => getenv('DB_USERNAME'),
        'password' => getenv('DB_PASSWORD'),
    ],
    'r2' => [
        'endpoint'  => getenv('R2_ENDPOINT'),
        'bucket'    => getenv('R2_BUCKET') ?: 'aatm-share-cards',
        'accessKey' => getenv('R2_ACCESS_KEY'),
        'secretKey' => getenv('R2_SECRET_KEY'),
        'publicUrl' => getenv('R2_PUBLIC_URL') ?: 'https://card-cdn.americaatthemovies.com',
    ],
    'assets' => [
        'templates_dir' => __DIR__ . '/assets/templates'
    ],
];