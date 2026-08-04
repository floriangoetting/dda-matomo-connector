<?php

declare(strict_types=1);

function requiredEnvironment(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '') {
        throw new RuntimeException($name . ' is required.');
    }
    return $value;
}

function requiredSecret(string $name): string
{
    $path = requiredEnvironment($name . '_FILE');
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException($name . '_FILE must point to a regular secret file.');
    }
    $value = rtrim((string) file_get_contents($path), "\r\n");
    if ($value === '') {
        throw new RuntimeException($name . '_FILE must not be empty.');
    }
    return $value;
}

$basePath = '/var/www/html';
$config = [
    'connector_id' => requiredEnvironment('DDA_CONNECTOR_ID'),
    'shared_secret' => requiredSecret('DDA_CONNECTOR_SHARED_SECRET'),
    'admin_token_hash' => password_hash(requiredSecret('DDA_CONNECTOR_ADMIN_TOKEN'), PASSWORD_DEFAULT),
    'request_tolerance_seconds' => 300,
    'nonce_store_path' => $basePath . '/storage/nonces.json',
    'nonce_store_ttl_seconds' => 300,
    'query_timeout_ms' => 35000,
    'max_query_rows' => 400,
    'update_manifest_url' => '',
    'allow_self_update' => false,
    'matomo' => [
        'host' => requiredEnvironment('MATOMO_DATABASE_HOST'),
        'port' => (int) (getenv('MATOMO_DATABASE_PORT') ?: 3306),
        'database' => requiredEnvironment('MATOMO_DATABASE_DBNAME'),
        'username' => requiredEnvironment('MATOMO_DATABASE_USERNAME'),
        'password' => requiredSecret('MATOMO_DATABASE_PASSWORD'),
        'charset' => 'utf8mb4',
    ],
];

$temporaryPath = $basePath . '/config.php.tmp';
$contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
    throw new RuntimeException('The runtime connector config could not be written.');
}
if (!rename($temporaryPath, $basePath . '/config.php')) {
    throw new RuntimeException('The runtime connector config could not be installed.');
}
