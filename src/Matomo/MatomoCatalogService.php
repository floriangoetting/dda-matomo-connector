<?php

namespace DDA\MatomoConnector\Matomo;

use DDA\MatomoConnector\Support\Config;
use PDO;

final class MatomoCatalogService
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<string, mixed> */
    public function loadCatalog(): array
    {
        $pdo = $this->pdo();
        $schema = MatomoSchema::fromConfig($this->config);
        $schema->assertRequiredTablesReadable($pdo);

        return [
            'tablePrefix' => $schema->prefix(),
            'sites' => $this->loadSites($pdo, $schema),
            'availableColumns' => $this->loadAvailableColumns($pdo, $schema),
        ];
    }

    private function pdo(): PDO
    {
        $matomo = $this->config->matomo();
        $host = (string) ($matomo['host'] ?? '127.0.0.1');
        $port = (int) ($matomo['port'] ?? 3306);
        $database = (string) ($matomo['database'] ?? '');
        $charset = (string) ($matomo['charset'] ?? 'utf8mb4');
        $username = (string) ($matomo['username'] ?? '');
        $password = (string) ($matomo['password'] ?? '');

        if ($database === '' || $username === '') {
            throw new \RuntimeException('Matomo database and username must be configured.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @return list<array{id:int,title:string,mainUrl:string}> */
    private function loadSites(PDO $pdo, MatomoSchema $schema): array
    {
        $statement = $pdo->query('SELECT idsite, name, main_url FROM ' . $schema->quotedTable('site') . ' ORDER BY name ASC, idsite ASC');
        $rows = $statement === false ? [] : $statement->fetchAll();

        $sites = [];
        foreach ($rows as $row) {
            $id = (int) ($row['idsite'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $title = trim((string) ($row['name'] ?? ''));
            $sites[] = [
                'id' => $id,
                'title' => $title !== '' ? $title : 'Website ' . $id,
                'mainUrl' => trim((string) ($row['main_url'] ?? '')),
            ];
        }

        return $sites;
    }

    /** @return array<string, list<string>> */
    private function loadAvailableColumns(PDO $pdo, MatomoSchema $schema): array
    {
        $matomo = $this->config->matomo();
        $database = (string) ($matomo['database'] ?? '');
        $catalogTables = $schema->catalogTables();
        $physicalTables = array_keys($catalogTables);
        $canonicalTablesByLowerName = [];
        foreach ($catalogTables as $physicalTable => $canonicalTable) {
            $canonicalTablesByLowerName[strtolower($physicalTable)] = $canonicalTable;
        }
        $placeholders = implode(',', array_fill(0, count($physicalTables), '?'));
        $sql = "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)";
        $statement = $pdo->prepare($sql);
        $statement->execute([$database, ...$physicalTables]);

        $availableColumns = [];
        foreach ($statement->fetchAll() as $row) {
            $physicalTable = strtolower((string) ($row['TABLE_NAME'] ?? ''));
            $table = $canonicalTablesByLowerName[$physicalTable] ?? '';
            $column = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
            if ($table === '' || $column === '') {
                continue;
            }
            $availableColumns[$table] ??= [];
            if (!in_array($column, $availableColumns[$table], true)) {
                $availableColumns[$table][] = $column;
            }
        }

        return $availableColumns;
    }
}
