<?php

namespace DDA\MatomoConnector\Matomo;

use DDA\MatomoConnector\Support\Config;
use PDO;

final class MatomoQueryService
{
    private const PLAN_TYPE = 'matomo.mysql.queryPlan.v1';

    private const ALLOWED_TABLES = [
        'matomo_log_link_visit_action',
        'matomo_log_visit',
        'matomo_log_action',
        'matomo_site',
        'information_schema.columns',
    ];

    public function __construct(private Config $config)
    {
    }

    /** @param array<string, mixed> $body */
    public function execute(array $body): array
    {
        $operation = (string) ($body['operation'] ?? 'query');
        if ($operation !== 'query') {
            throw new \InvalidArgumentException('Connector operation must be query.');
        }

        $providerKey = (string) ($body['providerKey'] ?? 'matomo_mysql');
        if ($providerKey !== 'matomo_mysql') {
            throw new \InvalidArgumentException('Connector provider is not supported.');
        }

        $queryPlan = $body['queryPlan'] ?? null;
        if (!is_array($queryPlan)) {
            throw new \InvalidArgumentException('queryPlan must be an object.');
        }
        if (($queryPlan['type'] ?? '') !== self::PLAN_TYPE) {
            throw new \InvalidArgumentException('queryPlan.type is not supported.');
        }

        $limits = $this->normalizeLimits($queryPlan['limits'] ?? []);
        $queries = $queryPlan['queries'] ?? null;
        if (!is_array($queries)) {
            throw new \InvalidArgumentException('queryPlan.queries must be an object.');
        }

        $rowsQuery = $this->normalizeQuery($queries['rows'] ?? null, 'rows', $limits);
        $countQuery = $this->normalizeQuery($queries['count'] ?? null, 'count', $limits);
        $totalsQuery = array_key_exists('totals', $queries) && $queries['totals'] !== null
            ? $this->normalizeQuery($queries['totals'], 'totals', $limits)
            : null;

        $pdo = $this->pdo();
        $this->applySessionTimeout($pdo, $limits['timeoutMs']);
        $startedAt = microtime(true);

        $rows = $this->fetchAll($pdo, $rowsQuery);
        $countRows = $this->fetchAll($pdo, $countQuery);
        $totals = $totalsQuery === null ? [] : $this->fetchAll($pdo, $totalsQuery);

        $totalRows = (int) ($countRows[0]['totalRows'] ?? $countRows[0]['total'] ?? 0);
        $pagination = $this->readPagination($rowsQuery['sql']);
        $totalPages = $totalRows === 0 ? 0 : (int) ceil($totalRows / $pagination['limit']);

        error_log(json_encode([
            'event' => 'dda_connector_query_completed',
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'rowCount' => count($rows),
            'totalRows' => $totalRows,
            'limit' => $pagination['limit'],
            'offset' => $pagination['offset'],
        ], JSON_UNESCAPED_SLASHES));

        return [
            'rows' => $rows,
            'totals' => $totals,
            'pagination' => [
                'offset' => $pagination['offset'],
                'limit' => $pagination['limit'],
                'totalRows' => $totalRows,
                'currentPage' => $totalRows === 0 ? 0 : min($totalPages, intdiv($pagination['offset'], $pagination['limit']) + 1),
                'totalPages' => $totalPages,
            ],
        ];
    }

    /** @param mixed $limits */
    private function normalizeLimits($limits): array
    {
        if (!is_array($limits)) {
            throw new \InvalidArgumentException('queryPlan.limits must be an object.');
        }

        $configuredMaxRows = $this->config->int('max_query_rows', 400);
        $configuredTimeoutMs = $this->config->int('query_timeout_ms', 35000);
        $maxRows = (int) ($limits['maxRows'] ?? $configuredMaxRows);
        $timeoutMs = (int) ($limits['timeoutMs'] ?? $configuredTimeoutMs);

        if ($maxRows < 1 || $maxRows > $configuredMaxRows) {
            throw new \InvalidArgumentException('queryPlan.limits.maxRows is outside the allowed range.');
        }
        if ($timeoutMs < 1 || $timeoutMs > $configuredTimeoutMs) {
            throw new \InvalidArgumentException('queryPlan.limits.timeoutMs is outside the allowed range.');
        }

        return [
            'maxRows' => $maxRows,
            'timeoutMs' => $timeoutMs,
        ];
    }

    /** @param mixed $query */
    private function normalizeQuery($query, string $name, array $limits): array
    {
        if (!is_array($query)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name must be an object.");
        }

        $sql = trim((string) ($query['sql'] ?? ''));
        $params = $query['params'] ?? [];
        if (!is_array($params)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name.params must be an array.");
        }

        $this->validateSql($sql, $name, $limits);
        $this->validateParams($sql, $params, $name);

        return [
            'sql' => $sql,
            'params' => array_values($params),
        ];
    }

    private function validateSql(string $sql, string $name, array $limits): void
    {
        if ($sql === '') {
            throw new \InvalidArgumentException("queryPlan.queries.$name.sql is required.");
        }
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name.sql must be a SELECT statement.");
        }
        if (preg_match('/;|#|--|\/\*|\*\//', $sql)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name.sql contains unsafe SQL tokens.");
        }
        if (preg_match('/\b(ALTER|CALL|CREATE|DELETE|DROP|GRANT|INSERT|LOAD|LOCK|REPLACE|REVOKE|SET|TRUNCATE|UNION|UNLOCK|UPDATE|USE)\b/i', $sql)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name.sql contains a disallowed operation.");
        }
        if (preg_match('/\b(?:OUTFILE|DUMPFILE|INFILE)\b/i', $sql)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name.sql contains a disallowed file operation.");
        }

        $this->validateTables($sql, $name);

        if ($name === 'rows') {
            $pagination = $this->readPagination($sql);
            if ($pagination['limit'] > $limits['maxRows']) {
                throw new \InvalidArgumentException('Rows query limit exceeds queryPlan.limits.maxRows.');
            }
        }
    }

    private function validateTables(string $sql, string $name): void
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+((?:`?[A-Za-z0-9_]+`?\.)?`?[A-Za-z0-9_]+`?|\()/i', $sql, $matches);
        foreach ($matches[1] as $identifier) {
            if ($identifier === '(') {
                continue;
            }

            $normalized = strtolower(str_replace('`', '', $identifier));
            if (!in_array($normalized, self::ALLOWED_TABLES, true)) {
                throw new \InvalidArgumentException("queryPlan.queries.$name.sql references a disallowed table.");
            }
        }
    }

    /** @param array<int|string, mixed> $params */
    private function validateParams(string $sql, array $params, string $name): void
    {
        if (substr_count($sql, '?') !== count($params)) {
            throw new \InvalidArgumentException("queryPlan.queries.$name.params does not match SQL placeholders.");
        }

        foreach ($params as $param) {
            if ($param !== null && !is_scalar($param)) {
                throw new \InvalidArgumentException("queryPlan.queries.$name.params contains an unsupported value.");
            }
        }
    }

    private function readPagination(string $sql): array
    {
        if (!preg_match('/\bLIMIT\s+([0-9]+)\s+OFFSET\s+([0-9]+)\s*$/i', $sql, $match)) {
            throw new \InvalidArgumentException('Rows query must end with LIMIT and OFFSET.');
        }

        $limit = (int) $match[1];
        $offset = (int) $match[2];
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('Rows query pagination is invalid.');
        }

        return [
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function fetchAll(PDO $pdo, array $query): array
    {
        $statement = $pdo->prepare($query['sql']);
        if ($statement === false) {
            throw new \RuntimeException('Query preparation failed.');
        }

        $statement->execute($query['params']);
        return $statement->fetchAll();
    }

    private function applySessionTimeout(PDO $pdo, int $timeoutMs): void
    {
        $maxExecutionTime = max(1, $timeoutMs);
        try {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=' . $maxExecutionTime);
        } catch (\Throwable) {
            // Some MySQL-compatible shared hosts do not allow this session variable.
        }
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
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
