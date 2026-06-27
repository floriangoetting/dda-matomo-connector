# DDA Matomo Connector

Shared-hosting friendly Matomo connector for Drag & Drop Analytics.

This connector runs in the customer's infrastructure. It lets Drag & Drop Analytics query local Matomo MySQL metadata and execute signed read-only query plans without sending database credentials to the SaaS backend.

## Status

Implemented endpoints:

- `GET /v1/health`
- `GET /v1/capabilities`
- `GET /v1/update-check`
- `POST /v1/catalog`
- `POST /v1/query`

## Requirements

- PHP 8.1+
- PDO MySQL extension
- HTTPS in production
- A read-only MySQL user for the Matomo database

## Installation

1. Upload this repository to your web hosting account.
2. Point the web root to `public/`.
3. Open the connector URL in a browser.
4. Complete the setup wizard.
5. Store the generated connector id, shared secret, and admin token.
6. In Drag & Drop Analytics, create a Matomo HTTP Connector Data Source with the connector URL, connector id, and shared secret.

The setup wizard tests the Matomo MySQL connection, creates the nonce storage
directory, and writes `config.php`. Once `config.php` exists, the setup wizard is
disabled. Manual setup is still possible by copying `config.example.php` to
`config.php` and editing the values directly.

On Apache shared hosting, keep `public/.htaccess` uploaded. It sets
`index.php` as the default entry point and rewrites routes such as `/setup` and
`/v1/health` to the connector front controller. Without it, only direct URLs
such as `/index.php` may work.

If the host does not allow rewrite rules, use the explicit front-controller URL
instead. For example, set the Drag & Drop Analytics connector URL to
`https://connector.example.com/index.php`; DDA will then call endpoints such as
`https://connector.example.com/index.php/v1/health`.

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
- nonce replay protection when `nonce_store_path` is configured

For replay protection, configure `nonce_store_path` to a JSON file in a
directory writable by PHP. The connector locks this file while pruning expired
nonces and storing the current nonce. Set the value to an empty string only when
the hosting environment cannot provide a writable local path.

## Query Endpoint

`POST /v1/query` executes a signed Drag & Drop Analytics query plan:

```json
{
  "operation": "query",
  "providerKey": "matomo_mysql",
  "queryPlan": {
    "type": "matomo.mysql.queryPlan.v1",
    "queries": {
      "rows": { "sql": "SELECT ... LIMIT 100 OFFSET 0", "params": [] },
      "count": { "sql": "SELECT COUNT(*) AS totalRows FROM (...)", "params": [] },
      "totals": { "sql": "SELECT ...", "params": [] }
    },
    "limits": {
      "timeoutMs": 35000,
      "maxRows": 400
    }
  }
}
```

The connector does not build Matomo SQL. Drag & Drop Analytics builds the query plan, and the connector only executes validated read queries.

Query safety gates:

- accepted plan type: `matomo.mysql.queryPlan.v1`
- only `SELECT` statements
- no semicolons, SQL comments, multi-statements, writes, DDL, `UNION`, or file operations
- allowed tables only: `matomo_log_link_visit_action`, `matomo_log_visit`, `matomo_log_action`, `matomo_site`, `information_schema.COLUMNS`
- placeholder count must match bound parameters
- row query must end with `LIMIT n OFFSET n`
- `LIMIT` must not exceed `max_query_rows`

## Setup UI

When `config.php` is missing, `GET /setup` shows a browser-based setup wizard.
The wizard writes a PHP config file with:

- connector id
- shared secret
- admin token hash
- nonce storage path
- Matomo database connection
- optional update manifest URL
- optional self-update permission

The admin token is only shown during setup. It is required for browser-based
update checks at `GET /admin/update`.

## Updates

The connector exposes its running version through `/v1/health`,
`/v1/capabilities`, and `/v1/update-check`.

`GET /v1/update-check` is HMAC-authenticated and intended for Drag & Drop
Analytics. It returns the current version, the latest version from the configured
manifest, and whether an update is available.

Configure `update_manifest_url` with a JSON manifest:

```json
{
  "version": "0.3.0",
  "minimumPhpVersion": "8.1.0",
  "downloadUrl": "https://updates.example.com/dda-matomo-connector-0.3.0.zip",
  "sha256": "hex-encoded-sha256",
  "releaseNotesUrl": "https://updates.example.com/dda-matomo-connector/0.3.0"
}
```

Browser-based update checks are available at `GET /admin/update` and require the
admin token. Applying updates requires:

- `allow_self_update` set to `true`
- PHP `ZipArchive`
- writable connector files
- a manifest with `downloadUrl` and `sha256`

The updater verifies the ZIP hash, extracts the package, backs up the current
runtime files under `storage/updates/`, and replaces `public/`, `src/`,
`composer.json`, `README.md`, `LICENSE`, and `config.example.php`. It never
overwrites `config.php`, `storage/`, or `vendor/`.

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
