<?php

namespace DDA\MatomoConnector\Http;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }

    public function pathWithQuery(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $query = parse_url($uri, PHP_URL_QUERY);
        $normalizedPath = is_string($path) ? $path : '/';
        return is_string($query) && $query !== '' ? $normalizedPath . '?' . $query : $normalizedPath;
    }

    public function header(string $name): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$serverKey] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public function body(): string
    {
        $body = file_get_contents('php://input');
        return is_string($body) ? $body : '';
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $body = $this->body();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be valid JSON.');
        }

        return $decoded;
    }
}
