<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Config.php';
require dirname(__DIR__) . '/src/Matomo/MatomoSchema.php';
require dirname(__DIR__) . '/src/Matomo/MatomoQueryService.php';

use DDA\MatomoConnector\Matomo\MatomoQueryService;
use DDA\MatomoConnector\Matomo\MatomoSchema;
use DDA\MatomoConnector\Support\Config;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $messagePart)) {
            throw new RuntimeException('Unexpected exception message: ' . $error->getMessage());
        }
        return;
    }

    throw new RuntimeException('Expected exception was not thrown.');
}

$schema = new MatomoSchema('analytics_');
assertSameValue('analytics_', $schema->prefix(), 'Prefix must be preserved');
assertSameValue('analytics_log_visit', $schema->table('log_visit'), 'Physical table must use configured prefix');
assertSameValue('matomo_log_visit', $schema->canonicalTable('log_visit'), 'Catalog table must remain canonical');
assertThrows(fn () => new MatomoSchema('unsafe-prefix'), 'letters, numbers and underscores');
assertThrows(fn () => new MatomoSchema(str_repeat('x', 44)), '43 characters');

$config = new Config([
    'matomo' => [
        'table_prefix' => 'analytics_',
    ],
]);
$queryService = new MatomoQueryService($config);
$validateTables = new ReflectionMethod($queryService, 'validateTables');
$validateTables->invoke($queryService, 'SELECT * FROM `analytics_log_visit`', 'rows');
assertThrows(
    fn () => $validateTables->invoke($queryService, 'SELECT * FROM `matomo_log_visit`', 'rows'),
    'disallowed table'
);

fwrite(STDOUT, "Matomo schema tests passed.\n");
