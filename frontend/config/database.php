<?php

return [
    'host'     => $_ENV['DB_HOST']     ?? 'postgres',
    'port'     => $_ENV['DB_PORT']     ?? '5432',
    'dbname'   => $_ENV['DB_NAME']     ?? 'hek3d',
    'user'     => $_ENV['DB_USER']     ?? 'hek3d_user',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
];
