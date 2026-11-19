<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: (in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? 'local' : 'production');
$hostedConfig = __DIR__ . '/config.hosted.php';
$hostedDatabase = $environment === 'production' && is_file($hostedConfig) ? require $hostedConfig : [];
$databasePassword = getenv('DB_PASSWORD');

return [
    'app_name' => 'Scriptises',
    'app_url' => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'session_name' => 'scriptises_session',
    'database' => [
        'host' => getenv('DB_HOST') ?: ($hostedDatabase['host'] ?? '127.0.0.1'),
        'port' => (int) (getenv('DB_PORT') ?: ($hostedDatabase['port'] ?? 3306)),
        'name' => getenv('DB_NAME') ?: ($hostedDatabase['name'] ?? 'scriptises_test'),
        'user' => getenv('DB_USER') ?: ($hostedDatabase['user'] ?? 'root'),
        'password' => $databasePassword !== false ? $databasePassword : ($hostedDatabase['password'] ?? ''),
        'charset' => 'utf8mb4',
    ],
];
