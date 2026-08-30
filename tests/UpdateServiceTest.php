<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Config.php';
require dirname(__DIR__) . '/src/Support/Version.php';
require dirname(__DIR__) . '/src/Update/UpdateService.php';

use DDA\MatomoConnector\Support\Config;
use DDA\MatomoConnector\Support\Version;
use DDA\MatomoConnector\Update\UpdateService;

function assertUpdateValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}

function assertUpdateThrows(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $messagePart)) {
            throw new RuntimeException('Unexpected exception message: ' . $error->getMessage());
        }
        return;
    }

    throw new RuntimeException('Expected update exception was not thrown.');
}

$requestedUrls = [];
$service = new UpdateService(
    new Config([
        'update_manifest_url' => '',
        'allow_self_update' => true,
    ]),
    dirname(__DIR__),
    function (string $url) use (&$requestedUrls): string {
        $requestedUrls[] = $url;
        return json_encode([
            'version' => '0.6.0',
            'minimumPhpVersion' => '8.1.0',
            'downloadUrl' => 'https://github.com/example/connector/releases/download/v0.6.0/connector.zip',
            'sha256' => str_repeat('a', 64),
            'releaseNotesUrl' => 'https://github.com/example/connector/releases/tag/v0.6.0',
        ], JSON_THROW_ON_ERROR);
    }
);
$status = $service->check();
assertUpdateValue([Version::OFFICIAL_MANIFEST_URL], $requestedUrls, 'Official manifest must be used by default');
assertUpdateValue(Version::CONNECTOR, $status['currentVersion'], 'Current version must be returned');
assertUpdateValue('0.6.0', $status['latestVersion'], 'Latest version must come from the manifest');
assertUpdateValue(true, $status['updateAvailable'], 'Newer manifest version must be detected');
assertUpdateValue('official', $status['manifestSource'], 'Official manifest source must be reported');
assertUpdateValue(true, $status['selfUpdateEnabled'], 'Self-update setting must be reported');

$customService = new UpdateService(
    new Config(['update_manifest_url' => 'http://updates.example.test/manifest.json']),
    dirname(__DIR__),
    fn (): string => '{}'
);
assertUpdateThrows(fn () => $customService->check(), 'must use HTTPS');

fwrite(STDOUT, "Connector update service tests passed.\n");
