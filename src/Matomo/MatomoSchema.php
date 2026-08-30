<?php

namespace DDA\MatomoConnector\Matomo;

use DDA\MatomoConnector\Support\Config;
use PDO;

final class MatomoSchema
{
    public const DEFAULT_PREFIX = 'matomo_';

    /** @var list<string> */
    private const REQUIRED_TABLE_SUFFIXES = [
        'log_link_visit_action',
        'log_visit',
        'log_action',
        'site',
    ];

    private const MAX_PREFIX_LENGTH = 43;

    public function __construct(private string $tablePrefix)
    {
        $this->tablePrefix = self::validatePrefix($tablePrefix);
    }

    public static function fromConfig(Config $config): self
    {
        $matomo = $config->matomo();
        return new self((string) ($matomo['table_prefix'] ?? self::DEFAULT_PREFIX));
    }

    public static function validatePrefix(string $tablePrefix): string
    {
        $normalized = trim($tablePrefix);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Matomo table prefix is required.');
        }
        if (strlen($normalized) > self::MAX_PREFIX_LENGTH) {
            throw new \InvalidArgumentException('Matomo table prefix must not exceed 43 characters.');
        }
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $normalized)) {
            throw new \InvalidArgumentException('Matomo table prefix may contain only letters, numbers and underscores.');
        }

        return $normalized;
    }

    public function prefix(): string
    {
        return $this->tablePrefix;
    }

    public function table(string $suffix): string
    {
        if (!in_array($suffix, self::REQUIRED_TABLE_SUFFIXES, true)) {
            throw new \InvalidArgumentException('Unsupported Matomo table suffix.');
        }

        return $this->tablePrefix . $suffix;
    }

    public function canonicalTable(string $suffix): string
    {
        if (!in_array($suffix, self::REQUIRED_TABLE_SUFFIXES, true)) {
            throw new \InvalidArgumentException('Unsupported Matomo table suffix.');
        }

        return self::DEFAULT_PREFIX . $suffix;
    }

    /** @return list<string> */
    public function requiredTables(): array
    {
        return array_map(fn (string $suffix): string => $this->table($suffix), self::REQUIRED_TABLE_SUFFIXES);
    }

    /** @return array<string, string> */
    public function catalogTables(): array
    {
        $tables = [];
        foreach (['log_link_visit_action', 'log_visit', 'log_action'] as $suffix) {
            $tables[$this->table($suffix)] = $this->canonicalTable($suffix);
        }
        return $tables;
    }

    /** @return list<string> */
    public function allowedTables(): array
    {
        return [...$this->requiredTables(), 'information_schema.columns'];
    }

    public function quotedTable(string $suffix): string
    {
        return '`' . $this->table($suffix) . '`';
    }

    public function assertRequiredTablesReadable(PDO $pdo): void
    {
        foreach (self::REQUIRED_TABLE_SUFFIXES as $suffix) {
            $table = $this->table($suffix);
            try {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (\Throwable $error) {
                throw new \RuntimeException(
                    'Required Matomo table ' . $table . ' could not be read. Check the table prefix and SELECT permissions.',
                    0,
                    $error
                );
            }
        }
    }
}
