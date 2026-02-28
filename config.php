<?php

return [
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'queue' => 'card-image',
    ],

    'db' => [
        'host' => '67.222.12.208',
        'database' => 'dreamwarriordev_aatm_mock_db',
        'username' => 'dreamwarriordev_aatm_mock_dbuser',
        'password' => 'HV3ra5PHDQ.PqAk(',
    ],

    'r2' => [
        'endpoint'  => 'https://9a431a3523d4434220cf99798ae9e66e.r2.cloudflarestorage.com',
        'bucket'    => 'aatm-share-cards',
        'accessKey' => 'cc8db09f8c1a5e450fd183460da95ae8',
        'secretKey' => 'ed5f02dd508cd35f9da0bbe278576d200ef22a371398875fefdc387b4b3a8bf6',
        'publicUrl' => 'https://card-cdn.americaatthemovies.com',
    ],

    'assets' => [
        'templates_dir' => __DIR__ . '/assets/templates'
    ],
];
