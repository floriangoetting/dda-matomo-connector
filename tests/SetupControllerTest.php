<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Matomo/MatomoSchema.php';
require dirname(__DIR__) . '/src/Support/ConfigWriter.php';
require dirname(__DIR__) . '/src/Support/Version.php';
require dirname(__DIR__) . '/src/Setup/SetupController.php';

use DDA\MatomoConnector\Setup\SetupController;

function assertSetupContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function assertSetupNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

$basePath = dirname(__DIR__);
$controller = new SetupController($basePath, $basePath . '/config.php.test-missing');

ob_start();
$controller->handle('GET');
$freshSetup = (string) ob_get_clean();
assertSetupContains(
    'name="allow_self_update" value="1" checked',
    $freshSetup,
    'A fresh browser setup must enable self-updates by default.'
);

$_POST = [
    'setup_action' => 'save',
    'connector_id' => 'conn_test',
    'shared_secret' => 'test-secret',
    'admin_token' => 'test-token',
    'storage_path' => $basePath . '/storage/nonces.json',
    'matomo_host' => '127.0.0.1',
    'matomo_port' => '3306',
    'matomo_database' => 'matomo',
    'matomo_table_prefix' => 'matomo_',
    'matomo_username' => '',
    'matomo_password' => '',
];
ob_start();
$controller->handle('POST');
$uncheckedSetup = (string) ob_get_clean();
assertSetupNotContains(
    'name="allow_self_update" value="1" checked',
    $uncheckedSetup,
    'An explicitly unchecked self-update option must remain unchecked after validation errors.'
);

$_POST = [];

fwrite(STDOUT, "Connector setup tests passed.\n");
