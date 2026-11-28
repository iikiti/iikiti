# Object Storage Plugin (`iikiti-storage-s3`)

`iikiti-storage-s3` is a first-party, opt-in application-scoped plugin (`plugins/iikiti-storage-s3/`) that stores Digital Asset Manager originals, historical versions, and generated variants in any S3-compatible object store. It uses pure-PHP AWS Signature Version 4 (`AwsSigV4Signer`) over Symfony's `HttpClientInterface` with no additional Composer SDK dependencies.

See [`../digital-asset-manager.md`](../digital-asset-manager.md) for core DAM delivery, caching, and signed URL behavior.

## Supported providers and presets

The plugin registers three `AssetStorageProviderInterface` drivers (`s3`, `r2`, and `gcs`):

| Driver (`ASSET_STORAGE_DRIVER`) | Target service | Default endpoint | Default region | Path style |
| --- | --- | --- | --- | --- |
| `s3` | AWS S3, MinIO, DigitalOcean Spaces, Ceph RGW, etc. | `https://s3.<region>.amazonaws.com` (or custom `ASSET_S3_ENDPOINT`) | `us-east-1` | Virtual-hosted (`false`), set `ASSET_S3_PATH_STYLE=1` for MinIO / custom endpoints |
| `r2` | Cloudflare R2 | Required: `https://<account-id>.r2.cloudflarestorage.com` | `auto` | Forced `true` |
| `gcs` | Google Cloud Storage (S3 XML Interop / HMAC keys) | `https://storage.googleapis.com` | `auto` | Forced `true` |

## Installation and activation

`iikiti-storage-s3` ships under `plugins/iikiti-storage-s3/` with `activation_mode: opt_in` and `scope: application`.

1. Enable the plugin and rebuild the container:

```sh
php bin/console iikiti:plugin:enable iikiti-storage-s3
php bin/console cache:clear
```

2. Configure credentials via environment variables or the Admin API (below).
3. Verify connectivity with `POST /api/admin/plugins/iikiti-storage-s3/test`.
4. Migrate existing local assets (if any) using `iikiti:asset:migrate-storage`.
5. Switch `ASSET_STORAGE_DRIVER` to `s3`, `r2`, or `gcs` and restart web and Messenger workers:

```sh
php bin/console cache:clear
php bin/console messenger:restart-workers
```

## Configuration

Configuration is resolved by `S3StorageConfigResolver` in precedence order: Application plugin configuration (plus encrypted `SecretsApi` secret key) overrides environment variable defaults.

### Environment variables

```dotenv
# Select active storage driver: local | s3 | r2 | gcs
ASSET_STORAGE_DRIVER=s3

# S3-compatible connection settings
ASSET_S3_ENDPOINT=
ASSET_S3_REGION=us-east-1
ASSET_S3_BUCKET=my-iikiti-assets
ASSET_S3_ACCESS_KEY=AKIA...
ASSET_S3_SECRET_KEY=...
ASSET_S3_PREFIX=cms-assets
ASSET_S3_PATH_STYLE=0
ASSET_S3_CDN_URL=
```

| Variable | Default | Purpose |
| --- | --- | --- |
| `ASSET_S3_ENDPOINT` | `""` | Custom S3-compatible endpoint URL. Leave empty for standard AWS S3 (`https://s3.<region>.amazonaws.com`) or GCS (`https://storage.googleapis.com`). Required for Cloudflare R2 and MinIO. |
| `ASSET_S3_REGION` | `us-east-1` | Signing region (`auto` is automatically applied for `r2` and `gcs` unless overridden). |
| `ASSET_S3_BUCKET` | `""` | Target bucket name. |
| `ASSET_S3_ACCESS_KEY` | `""` | Access key ID (or GCS Interop HMAC Access ID). |
| `ASSET_S3_SECRET_KEY` | `""` | Secret access key (or GCS Interop HMAC Secret). |
| `ASSET_S3_PREFIX` | `""` | Optional object key prefix inside the bucket (leading/trailing slashes normalized). |
| `ASSET_S3_PATH_STYLE` | `false` (`0`) | Set to `1` for path-style URLs (`<endpoint>/<bucket>/<key>`), required by MinIO and most self-hosted S3 gateways. |
| `ASSET_S3_CDN_URL` | `""` | Optional public CDN origin mapped to this bucket for public asset redirects. |

### Provider configuration examples

**AWS S3:**

```dotenv
ASSET_STORAGE_DRIVER=s3
ASSET_S3_REGION=us-east-1
ASSET_S3_BUCKET=acme-cms-assets
ASSET_S3_ACCESS_KEY=AKIA...
ASSET_S3_SECRET_KEY=...
ASSET_S3_PATH_STYLE=0
```

**Cloudflare R2:**

```dotenv
ASSET_STORAGE_DRIVER=r2
ASSET_S3_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
ASSET_S3_REGION=auto
ASSET_S3_BUCKET=acme-cms-assets
ASSET_S3_ACCESS_KEY=...
ASSET_S3_SECRET_KEY=...
ASSET_S3_CDN_URL=https://media.example.com
```

**MinIO (self-hosted):**

```dotenv
ASSET_STORAGE_DRIVER=s3
ASSET_S3_ENDPOINT=https://minio.internal:9000
ASSET_S3_REGION=us-east-1
ASSET_S3_BUCKET=iikiti-assets
ASSET_S3_ACCESS_KEY=minioadmin
ASSET_S3_SECRET_KEY=...
ASSET_S3_PATH_STYLE=1
```

**Google Cloud Storage (S3 Interoperability):**

Create an HMAC key in Google Cloud Storage (Settings → Interoperability) for a service account with object read/write/delete permissions on the bucket:

```dotenv
ASSET_STORAGE_DRIVER=gcs
ASSET_S3_BUCKET=acme-gcs-assets
ASSET_S3_ACCESS_KEY=GOOG1E...
ASSET_S3_SECRET_KEY=...
```

### Admin API and `SecretsApi` storage

Administrators (`ROLE_ADMIN`) can manage settings at runtime via `StorageS3AdminController` (guarded by `#[RequiresPlugin('iikiti-storage-s3')]`):

- **`GET /api/admin/plugins/iikiti-storage-s3/config`**: returns the active driver, available drivers, and sanitized configuration (`secretKey` is never returned in plaintext).
- **`PUT /api/admin/plugins/iikiti-storage-s3/config`**: updates Application plugin configuration (`provider`, `endpoint`, `region`, `bucket`, `accessKey`, `prefix`, `pathStyle`, `cdnUrl`).
  - Passing `secretKey` stores it encrypted via `iikiti\CMS\Secrets\SecretsApi` under `plugin.iikiti-storage-s3.secret_key` (requires `SECRETS_ACTIVE_KEY_ID` and `SECRETS_KEYRING`; see [`../plugin-development.md#secrets-api`](../plugin-development.md#secrets-api)).
  - Passing `"clearSecretKey": true` deletes the stored encrypted secret so configuration falls back to `ASSET_S3_SECRET_KEY`.
  - Updates are audited via `AuditRecorder`.
- **`POST /api/admin/plugins/iikiti-storage-s3/test`**: validates bucket access and optionally performs a write/head/delete round-trip probe (JSON body `{ "writeProbe": true }` by default; pass `{ "writeProbe": false }` for a read-only check).

## Proxy vs. presigned redirect delivery

- **`ASSET_DELIVERY_MODE=proxy` (default):** The CMS streams bytes from S3 through `AssetStreamResponseFactory` with strong `ETag` / `304`, `Cache-Control`, `HEAD`, and byte-range (`206`/`416`) support. Ideal when buckets are strictly private or behind an internal VPC endpoint.
- **`ASSET_DELIVERY_MODE=redirect`:**
  - For **public, non-signed** assets when `ASSET_CDN_BASE_URL` (or `ASSET_S3_CDN_URL`) is configured, the CMS returns `302 Found` (`Cache-Control: private, no-store`) redirecting to the public CDN URL.
  - For **private** or **signed** assets (or public assets without a CDN URL), `S3AssetStorage::temporaryUrl()` generates a SigV4 presigned `GET` URL expiring after `ASSET_REDIRECT_TTL_SECONDS` (default `300`, clamped `1..3600`), with `response-content-type` and `response-content-disposition` bound into the signature.

```dotenv
ASSET_DELIVERY_MODE=redirect
ASSET_CDN_BASE_URL=https://cdn.example.com
ASSET_REDIRECT_TTL_SECONDS=300
```

> **Note:** When using `ASSET_DELIVERY_MODE=redirect` with private presigned URLs, the `ASSET_S3_ENDPOINT` must be reachable by end-user browsers (do not use an internal-only MinIO hostname unless `ASSET_DELIVERY_MODE=proxy`).

## Optional `FlysystemBridgeStorage`

If your application installs `league/flysystem` and wants to use another Flysystem adapter (such as Azure Blob Storage, SFTP, or Google Cloud native) without introducing a hard Flysystem dependency in core, `iikiti-storage-s3` provides `iikiti\Extension\StorageS3\Storage\FlysystemBridgeStorage` and `FlysystemAdapterInterface`.

1. Implement `FlysystemAdapterInterface` as a thin wrapper delegating to `League\Flysystem\FilesystemOperator` (`writeStream`, `readStream`, `fileExists`, `fileSize`, `delete`, `temporaryUrl`, `publicUrl`).
2. Wrap that adapter in `new FlysystemBridgeStorage($adapter, driverName: 'my-driver', prefix: 'assets', supportsTemporaryUrls: true, cacheDirectory: $cacheDir, mimeRegistry: $mimeRegistry)`.
3. Expose it via a custom `AssetStorageProviderInterface` service (see [`../plugin-development.md`](../plugin-development.md#custom-storage-backends-assetstorageproviderinterface)).

`FlysystemBridgeStorage` enforces iikiti's strict storage key pattern (`^[a-f0-9]{2}/[a-f0-9]{64}\.[a-z0-9]{1,12}$`), MIME allowlist validation, and temporary local file materialization automatically.

## Migrating existing assets (`iikiti:asset:migrate-storage`)

Use `php bin/console iikiti:asset:migrate-storage` to copy uploaded asset originals and generated variants between any two configured storage drivers in keyset-paginated batches.

### Options

| Option | Default | Description |
| --- | --- | --- |
| `--from` | `local` | Source storage driver name. |
| `--to` | `s3` | Destination storage driver name (e.g. `s3`, `r2`, `gcs`, `local`). |
| `--batch-size` | `100` | Number of uploaded digital assets processed per batch (`1..10000`). |
| `--verify-checksum` | off | Re-reads each migrated object from the destination and verifies its SHA-256 checksum matches the database record (or source stream). |
| `--delete-source` | off | Removes source objects after successful copy (and checksum verification when `--verify-checksum` is set). |
| `--dry-run` | off | Reports objects that would be migrated without writing or deleting anything. |

### Recommended zero-downtime cutover walkthrough

1. Keep `ASSET_STORAGE_DRIVER=local` while configuring `ASSET_S3_*` credentials and verifying connectivity via `POST /api/admin/plugins/iikiti-storage-s3/test`.
2. Preview the migration with `--dry-run`:

```sh
php bin/console iikiti:asset:migrate-storage --from=local --to=s3 --dry-run
```

3. Run the migration with checksum verification (leaving source files intact):

```sh
php bin/console iikiti:asset:migrate-storage --from=local --to=s3 --batch-size=100 --verify-checksum
```

4. Switch `ASSET_STORAGE_DRIVER=s3` (or `r2` / `gcs`), clear the Symfony cache, and restart web + Messenger workers.
5. Re-run the migration command once more to copy any assets uploaded during the cutover window (already-migrated objects are skipped idempotently).
6. Once verified in production, optionally re-run with `--verify-checksum --delete-source` (or archive `var/digital-assets` offline) to reclaim local disk space.
