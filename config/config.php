<?php

declare(strict_types=1);

/*
 * Keep secrets in environment variables. These defaults suit a local Laragon
 * installation; change DB_NAME before running the migration if desired.
 */
return [
    'app_name' => 'Scriptises',
    'app_url' => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'session_name' => 'scriptises_session',
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        // Keep local exploratory data separate from a future production database.
        'name' => getenv('DB_NAME') ?: 'scriptises_test',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
];
