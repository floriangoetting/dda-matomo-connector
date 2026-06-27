<?php

namespace DDA\MatomoConnector\Auth;

final class FileNonceStore
{
    public function __construct(
        private string $path,
        private int $ttlSeconds
    ) {
    }

    public function remember(string $connectorId, string $nonce, int $requestTime): void
    {
        if ($nonce === '' || strlen($nonce) > 256) {
            throw new \RuntimeException('Invalid connector nonce.');
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Connector nonce store directory could not be created.');
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Connector nonce store could not be opened.');
        }

        $locked = false;
        try {
            $locked = flock($handle, LOCK_EX);
            if (!$locked) {
                throw new \RuntimeException('Connector nonce store could not be locked.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            if (!is_string($raw)) {
                throw new \RuntimeException('Connector nonce store could not be read.');
            }

            $storedNonces = $this->decodeStore($raw);
            $now = time();
            foreach ($storedNonces as $key => $expiresAt) {
                if (!is_int($expiresAt) || $expiresAt <= $now) {
                    unset($storedNonces[$key]);
                }
            }

            $nonceKey = hash('sha256', $connectorId . "\n" . $nonce);
            if (array_key_exists($nonceKey, $storedNonces)) {
                throw new \RuntimeException('Connector nonce was already used.');
            }

            $storedNonces[$nonceKey] = max($now, $requestTime) + $this->ttlSeconds;
            rewind($handle);
            if (!ftruncate($handle, 0)) {
                throw new \RuntimeException('Connector nonce store could not be truncated.');
            }
            if (fwrite($handle, json_encode($storedNonces, JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Connector nonce store could not be written.');
            }
            fflush($handle);
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    /** @return array<string, int> */
    private function decodeStore(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Connector nonce store contains invalid JSON.');
        }

        $storedNonces = [];
        foreach ($decoded as $key => $expiresAt) {
            if (is_string($key) && is_numeric($expiresAt)) {
                $storedNonces[$key] = (int) $expiresAt;
            }
        }

        return $storedNonces;
    }
}
