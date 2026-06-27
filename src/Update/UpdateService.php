<?php

namespace DDA\MatomoConnector\Update;

use DDA\MatomoConnector\Support\Config;
use DDA\MatomoConnector\Support\Version;
use ZipArchive;

final class UpdateService
{
    public function __construct(private Config $config, private string $basePath)
    {
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $manifestUrl = $this->config->string('update_manifest_url');
        $status = [
            'currentVersion' => Version::CONNECTOR,
            'latestVersion' => Version::CONNECTOR,
            'updateAvailable' => false,
            'manifestConfigured' => $manifestUrl !== '',
            'selfUpdateEnabled' => $this->config->bool('allow_self_update', false),
        ];

        if ($manifestUrl === '') {
            return $status;
        }

        $manifest = $this->fetchManifest($manifestUrl);
        $latestVersion = trim((string) ($manifest['version'] ?? Version::CONNECTOR));

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
        if ($downloadUrl === '' || $expectedHash === '') {
            throw new \RuntimeException('Update manifest must include downloadUrl and sha256.');
        }

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
        $zip->extractTo($extractPath);
        $zip->close();

        $sourcePath = $this->packageRoot($extractPath);
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
