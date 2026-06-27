<?php

namespace DDA\MatomoConnector\Support;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Connector config.php was not found. Copy config.example.php to config.php.');
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('Connector config.php must return an array.');
        }

        return new self($values);
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> */
    public function matomo(): array
    {
        $matomo = $this->values['matomo'] ?? [];
        return is_array($matomo) ? $matomo : [];
    }
}
