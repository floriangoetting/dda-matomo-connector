<?php

namespace DDA\MatomoConnector\Setup;

use DDA\MatomoConnector\Support\ConfigWriter;
use DDA\MatomoConnector\Support\Version;
use PDO;

final class SetupController
{
    public function __construct(private string $basePath, private string $configPath)
    {
    }

    public function handle(string $method): void
    {
        if (is_file($this->configPath)) {
            $this->renderPage('Setup complete', '<p>The connector is already configured.</p>');
            return;
        }

        if ($method === 'POST') {
            $this->submit();
            return;
        }

        $this->renderForm();
    }

    private function renderForm(array $values = [], string $error = ''): void
    {
        $connectorId = $values['connector_id'] ?? 'conn_' . bin2hex(random_bytes(8));
        $sharedSecret = $values['shared_secret'] ?? bin2hex(random_bytes(32));
        $adminToken = $values['admin_token'] ?? bin2hex(random_bytes(24));
        $storagePath = $values['storage_path'] ?? $this->basePath . '/storage/nonces.json';

        $errorHtml = $error === '' ? '' : '<div class="alert alert-danger">' . $this->escape($error) . '</div>';
        $body = $errorHtml . '
            <form method="post" class="setup-form">
                <h2>Connector</h2>
                ' . $this->input('connector_id', 'Connector ID', $connectorId) . '
                ' . $this->input('shared_secret', 'Shared Secret', $sharedSecret) . '
                ' . $this->input('admin_token', 'Admin Token', $adminToken) . '
                ' . $this->input('storage_path', 'Nonce Store Path', $storagePath) . '
                <h2>Matomo Database</h2>
                ' . $this->input('matomo_host', 'MySQL Host', $values['matomo_host'] ?? '127.0.0.1') . '
                ' . $this->input('matomo_port', 'MySQL Port', $values['matomo_port'] ?? '3306', 'number') . '
                ' . $this->input('matomo_database', 'Database', $values['matomo_database'] ?? 'matomo') . '
                ' . $this->input('matomo_username', 'Read-only Username', $values['matomo_username'] ?? '') . '
                ' . $this->input('matomo_password', 'Password', $values['matomo_password'] ?? '', 'password') . '
                <h2>Updates</h2>
                ' . $this->input('update_manifest_url', 'Update Manifest URL', $values['update_manifest_url'] ?? '') . '
                <label class="checkbox"><input type="checkbox" name="allow_self_update" value="1"> Allow this connector to apply verified ZIP updates</label>
                <button type="submit">Create config.php</button>
            </form>';

        $this->renderPage('DDA Matomo Connector Setup', $body);
    }

    private function submit(): void
    {
        $values = [
            'connector_id' => $this->post('connector_id'),
            'shared_secret' => $this->post('shared_secret'),
            'admin_token' => $this->post('admin_token'),
            'storage_path' => $this->post('storage_path'),
            'matomo_host' => $this->post('matomo_host'),
            'matomo_port' => $this->post('matomo_port'),
            'matomo_database' => $this->post('matomo_database'),
            'matomo_username' => $this->post('matomo_username'),
            'matomo_password' => $this->post('matomo_password'),
            'update_manifest_url' => $this->post('update_manifest_url'),
            'allow_self_update' => ($_POST['allow_self_update'] ?? '') === '1',
        ];

        try {
            $this->validate($values);
            $this->testConnection($values);
            $this->prepareStorage($values['storage_path']);

            ConfigWriter::write($this->configPath, [
                'connector_id' => $values['connector_id'],
                'shared_secret' => $values['shared_secret'],
                'admin_token_hash' => password_hash($values['admin_token'], PASSWORD_DEFAULT),
                'request_tolerance_seconds' => 300,
                'nonce_store_path' => $values['storage_path'],
                'nonce_store_ttl_seconds' => 300,
                'query_timeout_ms' => 35000,
                'max_query_rows' => 400,
                'update_manifest_url' => $values['update_manifest_url'],
                'allow_self_update' => $values['allow_self_update'],
                'matomo' => [
                    'host' => $values['matomo_host'],
                    'port' => (int) $values['matomo_port'],
                    'database' => $values['matomo_database'],
                    'username' => $values['matomo_username'],
                    'password' => $values['matomo_password'],
                    'charset' => 'utf8mb4',
                ],
            ]);
        } catch (\Throwable $error) {
            $this->renderForm($values, $error->getMessage());
            return;
        }

        $this->renderPage('Setup complete', '
            <div class="alert alert-success">config.php was created successfully.</div>
            <dl>
                <dt>Connector URL</dt><dd>' . $this->escape($this->connectorUrl()) . '</dd>
                <dt>Connector ID</dt><dd><code>' . $this->escape($values['connector_id']) . '</code></dd>
                <dt>Shared Secret</dt><dd><code>' . $this->escape($values['shared_secret']) . '</code></dd>
                <dt>Admin Token</dt><dd><code>' . $this->escape($values['admin_token']) . '</code></dd>
            </dl>
            <p>Store these values now. The shared secret and admin token are not shown again by the connector.</p>
        ');
    }

    /** @param array<string, mixed> $values */
    private function validate(array $values): void
    {
        foreach (['connector_id', 'shared_secret', 'admin_token', 'storage_path', 'matomo_host', 'matomo_database', 'matomo_username'] as $key) {
            if (trim((string) $values[$key]) === '') {
                throw new \InvalidArgumentException("$key is required.");
            }
        }

        $port = (int) $values['matomo_port'];
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('matomo_port must be between 1 and 65535.');
        }
    }

    /** @param array<string, mixed> $values */
    private function testConnection(array $values): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $values['matomo_host'],
            (int) $values['matomo_port'],
            $values['matomo_database']
        );
        $pdo = new PDO($dsn, (string) $values['matomo_username'], (string) $values['matomo_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
    }

    private function prepareStorage(string $storagePath): void
    {
        $directory = dirname($storagePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Storage directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Storage directory is not writable by PHP.');
        }
    }

    private function input(string $name, string $label, string $value, string $type = 'text'): string
    {
        return '<label>' . $this->escape($label) . '<input type="' . $this->escape($type) . '" name="' . $this->escape($name) . '" value="' . $this->escape($value) . '"></label>';
    }

    private function post(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function connectorUrl(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptPath = str_replace('/public/index.php', '', $_SERVER['SCRIPT_NAME'] ?? '');
        return $scheme . '://' . $host . rtrim($scriptPath, '/');
    }

    private function renderPage(string $title, string $body): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->escape($title) . '</title><style>
            body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#1f2933}
            main{max-width:760px;margin:40px auto;padding:32px;background:#fff;border:1px solid #d9dee7;border-radius:8px}
            h1{margin-top:0;font-size:28px} h2{margin-top:28px;font-size:18px}
            label{display:block;margin:14px 0;font-weight:600} input{box-sizing:border-box;width:100%;padding:10px;margin-top:6px;border:1px solid #b8c0cc;border-radius:6px;font:inherit}
            .checkbox{font-weight:400}.checkbox input{width:auto;margin-right:8px}.alert{padding:12px 14px;border-radius:6px;margin-bottom:20px}.alert-danger{background:#fde8e8;color:#9b1c1c}.alert-success{background:#e6f4ea;color:#1e7e34}
            button{padding:10px 16px;border:0;border-radius:6px;background:#215cff;color:#fff;font:inherit;font-weight:700;cursor:pointer}
            code{background:#eef2f7;padding:2px 5px;border-radius:4px} dd{margin:6px 0 14px}
        </style></head><body><main><h1>' . $this->escape($title) . '</h1>' . $body . '<footer><p>DDA Matomo Connector ' . Version::CONNECTOR . '</p></footer></main></body></html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
