# DDA Matomo Connector

Shared-hosting friendly Matomo connector for Drag & Drop Analytics.

This connector runs in the customer's infrastructure. It lets Drag & Drop Analytics query local Matomo MySQL metadata without sending database credentials to the SaaS backend.

## Status

Early scaffold. Implemented endpoints:

- `GET /v1/health`
- `GET /v1/capabilities`
- `POST /v1/catalog`

Query execution is intentionally not implemented yet.

## Requirements

- PHP 8.1+
- PDO MySQL extension
- HTTPS in production
- A read-only MySQL user for the Matomo database

## Installation

1. Upload this repository to your web hosting account.
2. Point the web root to `public/`.
3. Copy `config.example.php` to `config.php`.
4. Configure connector id, shared secret, and Matomo database credentials.
5. In Drag & Drop Analytics, create a Matomo HTTP Connector Data Source with the connector URL, connector id, and shared secret.

## Security Model

Drag & Drop Analytics signs connector requests with HMAC-SHA256.

Required headers:

```text
X-DDA-Connector-Id
X-DDA-Timestamp
X-DDA-Nonce
X-DDA-Signature
```

Canonical request:

```text
METHOD
PATH_WITH_QUERY
TIMESTAMP
NONCE
SHA256_BODY
```

The connector validates:

- connector id
- timestamp tolerance
- HMAC signature

Nonce replay persistence is not implemented in this scaffold yet.

## Local Development

Run PHP syntax checks:

```bash
composer run lint
```

Or without Composer:

```bash
find public src -name '*.php' -print0 | xargs -0 -n1 php -l
```

## License

Apache-2.0
