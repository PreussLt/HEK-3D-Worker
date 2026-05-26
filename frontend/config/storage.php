<?php

return [
    'endpoint'   => $_ENV['RUSTFS_ENDPOINT']   ?? 'http://rustfs:9000',
    'access_key' => $_ENV['RUSTFS_ACCESS_KEY']  ?? '',
    'secret_key' => $_ENV['RUSTFS_SECRET_KEY']  ?? '',
    'buckets'    => [
        'logos'  => $_ENV['RUSTFS_BUCKET_LOGOS']  ?? 'logos',
        'models' => $_ENV['RUSTFS_BUCKET_MODELS'] ?? 'models',
        'output' => $_ENV['RUSTFS_BUCKET_OUTPUT'] ?? 'output',
    ],
];
