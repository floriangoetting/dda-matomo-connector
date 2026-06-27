<?php

return [
    'connector_id' => 'conn_replace_me',
    'shared_secret' => 'replace-with-a-long-random-secret',
    'admin_token_hash' => 'replace-with-password_hash-output',
    'request_tolerance_seconds' => 300,
    'nonce_store_path' => __DIR__ . '/storage/nonces.json',
    'nonce_store_ttl_seconds' => 300,
    'query_timeout_ms' => 35000,
    'max_query_rows' => 400,
    'update_manifest_url' => '',
    'allow_self_update' => false,
    'matomo' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'matomo',
        'username' => 'matomo_readonly',
        'password' => 'replace-me',
        'charset' => 'utf8mb4',
    ],
];
