<?php

namespace DDA\MatomoConnector\Update;

use DDA\MatomoConnector\Auth\AdminAuthenticator;
use DDA\MatomoConnector\Support\Config;

final class UpdateController
{
    public function __construct(
        private Config $config,
        private string $basePath
    ) {
    }

    public function handle(string $method): void
    {
        $token = is_string($_POST['admin_token'] ?? null)
            ? trim((string) $_POST['admin_token'])
            : (is_string($_GET['token'] ?? null) ? trim((string) $_GET['token']) : '');

        if (!(new AdminAuthenticator($this->config))->verify($token)) {
            $this->render('Connector Updates', $this->tokenForm(''));
            return;
        }

        $service = new UpdateService($this->config, $this->basePath);
        try {
            $status = $method === 'POST' && ($_POST['action'] ?? '') === 'apply'
                ? $service->apply()
                : $service->check();
            $this->render('Connector Updates', $this->statusMarkup($status, $token));
        } catch (\Throwable $error) {
            $this->render('Connector Updates', '<div class="alert alert-danger">' . $this->escape($error->getMessage()) . '</div>' . $this->tokenForm($token));
        }
    }

    /** @param array<string, mixed> $status */
    private function statusMarkup(array $status, string $token): string
    {
        $rows = '';
        foreach ($status as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            if ($value === null || is_array($value)) {
                continue;
            }
            $rows .= '<dt>' . $this->escape((string) $key) . '</dt><dd>' . $this->escape((string) $value) . '</dd>';
        }

        $applyButton = $status['updateAvailable'] && $status['selfUpdateEnabled']
            ? '<form method="post"><input type="hidden" name="admin_token" value="' . $this->escape($token) . '"><input type="hidden" name="action" value="apply"><button type="submit">Apply Update</button></form>'
            : '';

        return '<dl>' . $rows . '</dl>' . $applyButton;
    }

    private function tokenForm(string $token): string
    {
        return '<form method="post"><label>Admin Token<input type="password" name="admin_token" value="' . $this->escape($token) . '"></label><button type="submit">Check Updates</button></form>';
    }

    private function render(string $title, string $body): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->escape($title) . '</title><style>
            body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#1f2933}
            main{max-width:720px;margin:40px auto;padding:32px;background:#fff;border:1px solid #d9dee7;border-radius:8px}
            label{display:block;margin:14px 0;font-weight:600} input{box-sizing:border-box;width:100%;padding:10px;margin-top:6px;border:1px solid #b8c0cc;border-radius:6px;font:inherit}
            button{padding:10px 16px;border:0;border-radius:6px;background:#215cff;color:#fff;font:inherit;font-weight:700;cursor:pointer}
            .alert{padding:12px 14px;border-radius:6px;margin-bottom:20px}.alert-danger{background:#fde8e8;color:#9b1c1c} dd{margin:6px 0 14px}
        </style></head><body><main><h1>' . $this->escape($title) . '</h1>' . $body . '</main></body></html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
