# DDA Matomo Connector

Shared-hosting friendly Matomo connector for Drag & Drop Analytics.

This connector runs in the customer's infrastructure. It lets Drag & Drop Analytics query local Matomo MySQL metadata and execute signed read-only query plans without sending database credentials to the central application.

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

## Shared Hosting Installation

Docker is not required. The connector remains a regular PHP application for
Apache-compatible shared hosting:

1. Upload this repository to your web hosting account.
2. Point the web root to `public/`.
3. Open the connector URL in a browser.
4. Complete the setup wizard.
5. Store the generated connector id, shared secret, and admin token.
6. In Drag & Drop Analytics, create a Matomo HTTP Connector Data Source with the connector URL, connector id, and shared secret.

The setup wizard asks for the Matomo table prefix (default `matomo_`) and tests
read access to the required Matomo tables before saving. It repeats that test
when creating `config.php`, creates the nonce storage directory, and writes the
config file only after the connection and table checks succeed. Once
`config.php` exists, the setup wizard is disabled. Manual setup is still
possible by copying `config.example.php` to `config.php` and editing the values
directly.

On Apache shared hosting, keep `public/.htaccess` uploaded. It sets
`index.php` as the default entry point and rewrites routes such as `/setup` and
`/v1/health` to the connector front controller. Without it, only direct URLs
such as `/index.php` may work.

If the host does not allow rewrite rules, use the explicit front-controller URL
instead. For example, set the Drag & Drop Analytics connector URL to
`https://connector.example.com/index.php`; DDA will then call endpoints such as
`https://connector.example.com/index.php/v1/health`.

## Docker Installation

Docker is an additional supported deployment option for users who operate
Matomo on a Docker host or another container platform. It does not replace the
shared-hosting installation above. The image contains Apache, PHP 8.3, PDO
MySQL, and the connector application.

The container does not use the browser setup wizard. On every start it creates
`config.php` from environment metadata and mounted secret files. Prepare the
secret files locally:

```bash
install -m 0700 -d secrets
openssl rand -hex 48 > secrets/connector_shared_secret
openssl rand -hex 32 > secrets/connector_admin_token
printf '%s' 'the-password-of-your-read-only-matomo-user' > secrets/matomo_readonly_password
chmod 0600 secrets/*
printf 'DDA_CONNECTOR_ID=conn_%s\n' "$(openssl rand -hex 8)" > .env
printf '%s\n' 'MATOMO_DOCKER_NETWORK=your-matomo-network' >> .env
```

The Matomo password must belong to an existing database user that has only
`SELECT` privileges on the Matomo database. Keep all three secret files out of
Git.

Add the connector to a Compose project that can reach the Matomo database. The
following example expects an existing external Docker network named in
`MATOMO_DOCKER_NETWORK` and a database container reachable as `matomo-db`:

```yaml
services:
  dda-matomo-connector:
    build:
      context: .
    image: dda-matomo-connector:0.4.0
    restart: unless-stopped
    environment:
      DDA_CONNECTOR_ID: ${DDA_CONNECTOR_ID:?DDA_CONNECTOR_ID must be set}
      DDA_CONNECTOR_SHARED_SECRET_FILE: /run/secrets/connector_shared_secret
      DDA_CONNECTOR_ADMIN_TOKEN_FILE: /run/secrets/connector_admin_token
      MATOMO_DATABASE_HOST: ${MATOMO_DATABASE_HOST:-matomo-db}
      MATOMO_DATABASE_PORT: ${MATOMO_DATABASE_PORT:-3306}
      MATOMO_DATABASE_DBNAME: ${MATOMO_DATABASE_DBNAME:-matomo}
      MATOMO_DATABASE_TABLES_PREFIX: ${MATOMO_DATABASE_TABLES_PREFIX:-matomo_}
      MATOMO_DATABASE_USERNAME: ${MATOMO_DATABASE_USERNAME:-matomo_readonly}
      MATOMO_DATABASE_PASSWORD_FILE: /run/secrets/matomo_readonly_password
    ports:
      - "127.0.0.1:8091:80"
    volumes:
      - connector_storage:/var/www/html/storage
      - ./secrets/connector_shared_secret:/run/secrets/connector_shared_secret:ro
      - ./secrets/connector_admin_token:/run/secrets/connector_admin_token:ro
      - ./secrets/matomo_readonly_password:/run/secrets/matomo_readonly_password:ro
    networks:
      - matomo
    healthcheck:
      test: ["CMD-SHELL", "php -r '$$body = @file_get_contents(\"http://127.0.0.1/v1/health\"); exit($$body !== false && str_contains($$body, \"ok\") ? 0 : 1);'"]
      interval: 15s
      timeout: 5s
      retries: 10

volumes:
  connector_storage:

networks:
  matomo:
    external: true
    name: ${MATOMO_DOCKER_NETWORK:?MATOMO_DOCKER_NETWORK must be set}
```

Build and start it with:

```bash
docker compose build --pull dda-matomo-connector
docker compose up -d dda-matomo-connector
docker compose ps dda-matomo-connector
```

Keep port `8091` bound to loopback and publish it through an existing HTTPS
reverse proxy, or connect the reverse proxy directly to the same Docker
network. Configure the resulting HTTPS URL as the connector URL in Drag & Drop
Analytics, together with the connector ID from `.env` and the exact shared
secret stored in `secrets/connector_shared_secret`. Never expose the Matomo
database port for this purpose.

Container updates are performed by checking out the desired connector release,
rebuilding the image, and recreating the service. Browser-based self-updates
remain disabled in the container runtime so that application files continue to
come from the immutable image.

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
- allowed tables only: the configured-prefix variants of `log_link_visit_action`, `log_visit`, `log_action`, `site`, plus `information_schema.COLUMNS`
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
- Matomo table prefix, validated as 1–43 letters, numbers or underscores
- optional permission for browser-based connector updates

The admin token is only shown during setup. It is required for browser-based
update checks at `GET /admin/update`.

## Updates

The connector exposes its installed version through `/v1/health` and
`/v1/capabilities`. Drag & Drop Analytics compares that version with the
official release manifest on its own server. The customer connector therefore
does not need outbound internet access for version detection.

The setup wizard uses the official Drag & Drop Analytics release manifest by
default. A custom HTTPS `update_manifest_url` can still be set directly in
`config.php` for a maintained fork. The manifest format is:

```json
{
  "version": "0.5.0",
  "minimumPhpVersion": "8.1.0",
  "downloadUrl": "https://github.com/floriangoetting/dda-matomo-connector/releases/download/v0.5.0/dda-matomo-connector-0.5.0.zip",
  "sha256": "hex-encoded-sha256",
  "releaseNotesUrl": "https://github.com/floriangoetting/dda-matomo-connector/releases/tag/v0.5.0"
}
```

Browser-based update checks are available at `GET /admin/update` and require the
admin token. The connector contacts the manifest and package hosts only after an
administrator explicitly checks or applies an update there. Applying updates
requires:

- `allow_self_update` set to `true`
- PHP `ZipArchive`
- writable connector files
- a manifest with `downloadUrl` and `sha256`

The updater requires HTTPS, verifies the ZIP hash, rejects unsafe archive paths
and symbolic links, extracts the package, backs up the current runtime files
under `storage/updates/`, and replaces `public/`, `src/`,
`composer.json`, `README.md`, `LICENSE`, and `config.example.php`. It never
overwrites `config.php`, `storage/`, or `vendor/`.

`GET /v1/update-check` remains available for older Drag & Drop Analytics
versions, but current versions no longer call it for normal update detection.
Container images are immutable deployments, so browser-based file replacement
is disabled in the provided Docker runtime. Update the pinned image revision
through the deployment workflow instead.

## Local Development

Run PHP syntax checks:

```bash
composer run lint
```

Run the schema and query-allowlist regression test:

```bash
composer run test
```

Or without Composer:

```bash
find public src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Build the optional Docker runtime:

```bash
docker build -t dda-matomo-connector:0.4.0 .
```

## License

Apache-2.0
