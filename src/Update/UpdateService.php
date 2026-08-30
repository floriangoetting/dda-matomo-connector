<?php

namespace DDA\MatomoConnector\Update;

use DDA\MatomoConnector\Support\Config;
use DDA\MatomoConnector\Support\Version;
use ZipArchive;

final class UpdateService
{
    /** @var null|callable(string):string */
    private $resourceDownloader;

    public function __construct(private Config $config, private string $basePath, ?callable $resourceDownloader = null)
    {
        $this->resourceDownloader = $resourceDownloader;
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $configuredManifestUrl = $this->config->string('update_manifest_url');
        $manifestUrl = $configuredManifestUrl !== ''
            ? $configuredManifestUrl
            : Version::OFFICIAL_MANIFEST_URL;
        $this->assertHttpsUrl($manifestUrl, 'Update manifest URL');
        $status = [
            'currentVersion' => Version::CONNECTOR,
            'latestVersion' => null,
            'updateAvailable' => false,
            'manifestConfigured' => true,
            'manifestSource' => $configuredManifestUrl !== '' ? 'custom' : 'official',
            'selfUpdateEnabled' => $this->config->bool('allow_self_update', false),
        ];

        $manifest = $this->fetchManifest($manifestUrl);
        $latestVersion = trim((string) ($manifest['version'] ?? ''));
        if (!preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $latestVersion)) {
            throw new \RuntimeException('Update manifest must contain a valid version.');
        }

        return [
            ...$status,
            'latestVersion' => $latestVersion,
            'updateAvailable' => version_compare($latestVersion, Version::CONNECTOR, '>'),
            'minimumPhpVersion' => $manifest['minimumPhpVersion'] ?? null,
            'releaseNotesUrl' => $manifest['releaseNotesUrl'] ?? null,
            'downloadUrl' => $manifest['downloadUrl'] ?? null,
            'sha256' => $manifest['sha256'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function apply(): array
    {
        if (!$this->config->bool('allow_self_update', false)) {
            throw new \RuntimeException('Self-update is disabled in config.php.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The PHP ZipArchive extension is required for self-update.');
        }

        $status = $this->check();
        if (!$status['updateAvailable']) {
            return $status + ['applied' => false, 'message' => 'Connector is already up to date.'];
        }
        if (($status['minimumPhpVersion'] ?? null) && version_compare(PHP_VERSION, (string) $status['minimumPhpVersion'], '<')) {
            throw new \RuntimeException('This update requires PHP ' . $status['minimumPhpVersion'] . ' or newer.');
        }

        $downloadUrl = (string) ($status['downloadUrl'] ?? '');
        $expectedHash = (string) ($status['sha256'] ?? '');
        if ($downloadUrl === '' || !preg_match('/\A[a-f0-9]{64}\z/i', $expectedHash)) {
            throw new \RuntimeException('Update manifest must include downloadUrl and sha256.');
        }
        $this->assertHttpsUrl($downloadUrl, 'Update download URL');

        $updatesDir = $this->basePath . '/storage/updates';
        if (!is_dir($updatesDir) && !mkdir($updatesDir, 0770, true) && !is_dir($updatesDir)) {
            throw new \RuntimeException('Update storage directory could not be created.');
        }

        $packagePath = $updatesDir . '/connector-update.zip';
        $package = $this->download($downloadUrl);
        if (!hash_equals(strtolower($expectedHash), hash('sha256', $package))) {
            throw new \RuntimeException('Downloaded update package hash does not match the manifest.');
        }
        file_put_contents($packagePath, $package, LOCK_EX);

        $extractPath = $updatesDir . '/extract-' . date('YmdHis');
        mkdir($extractPath, 0770, true);
        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new \RuntimeException('Update package could not be opened.');
        }
        $this->assertSafeArchive($zip);
        $zip->extractTo($extractPath);
        $zip->close();

        $sourcePath = $this->packageRoot($extractPath);
        foreach (['public/index.php', 'src/Support/Version.php'] as $requiredEntry) {
            if (!is_file($sourcePath . '/' . $requiredEntry)) {
                throw new \RuntimeException('Update package is missing required connector files.');
            }
        }
        $backupPath = $updatesDir . '/backup-' . date('YmdHis');
        mkdir($backupPath, 0770, true);
        foreach (['public', 'src', 'composer.json', 'README.md', 'LICENSE', 'config.example.php'] as $entry) {
            $current = $this->basePath . '/' . $entry;
            if (file_exists($current)) {
                $this->copyPath($current, $backupPath . '/' . $entry);
            }
            $incoming = $sourcePath . '/' . $entry;
            if (file_exists($incoming)) {
                $this->copyPath($incoming, $current);
            }
        }

        return $status + [
            'applied' => true,
            'message' => 'Connector update was applied.',
            'backupPath' => $backupPath,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchManifest(string $manifestUrl): array
    {
        $raw = $this->download($manifestUrl);
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Update manifest is not valid JSON.');
        }

        return $manifest;
    }

    private function download(string $url): string
    {
        if ($this->resourceDownloader !== null) {
            $raw = ($this->resourceDownloader)($url);
            if ($raw === '') {
                throw new \RuntimeException('Update resource could not be downloaded.');
            }
            return $raw;
        }
        $context = stream_context_create([
            'http' => ['timeout' => 10],
            'https' => ['timeout' => 10],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Update resource could not be downloaded.');
        }

        return $raw;
    }

    private function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException($label . ' must use HTTPS.');
        }
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string) $zip->getNameIndex($index);
            $normalized = str_replace('\\', '/', $entry);
            if (
                $normalized === ''
                || str_starts_with($normalized, '/')
                || preg_match('/\A[A-Za-z]:\//', $normalized)
                || in_array('..', explode('/', $normalized), true)
            ) {
                $zip->close();
                throw new \RuntimeException('Update package contains an unsafe path.');
            }
            if (
                $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)
                && (($attributes >> 16) & 0170000) === 0120000
            ) {
                $zip->close();
                throw new \RuntimeException('Update package must not contain symbolic links.');
            }
        }
    }

    private function packageRoot(string $extractPath): string
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], fn ($entry) => $entry !== '.' && $entry !== '..'));
        if (count($entries) === 1 && is_dir($extractPath . '/' . $entries[0])) {
            return $extractPath . '/' . $entries[0];
        }

        return $extractPath;
    }

    private function copyPath(string $source, string $target): void
    {
        if (is_dir($source)) {
            if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
                throw new \RuntimeException('Update target directory could not be created.');
            }
            foreach (array_values(array_filter(scandir($source) ?: [], fn ($entry) => $entry !== '.' && $entry !== '..')) as $entry) {
                $this->copyPath($source . '/' . $entry, $target . '/' . $entry);
            }
            return;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Update target directory could not be created.');
        }
        if (!copy($source, $target)) {
            throw new \RuntimeException('Update file could not be copied.');
        }
    }
}
