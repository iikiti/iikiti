# Application Keys

Application keys are purpose-specific secrets. Keep them in the deployment
secret manager (or in an ignored, owner-readable `.env.local` for development),
never in committed configuration. Generate each key independently as 32 random
bytes. A key ID is a non-secret label identifying one key version; changing the
active ID selects which key is used for new data or signatures.

Do not add manually managed password salts. Password hashes use per-value salts,
and random API tokens do not need a static salt.

## Keys and Effects

### `APP_SECRET`

Symfony's framework secret. It is not an encryption key for new application
data and is not rotated by the application-key commands. Changing it can
invalidate Symfony-generated tokens, signatures, and related state (for example
CSRF tokens or remember-me state, depending on enabled features). It also breaks
legacy transfer-credential decryption and legacy guest-token verification while
their respective compatibility flags are enabled. Migrate legacy transfer rows
and let old guest tokens expire before changing `APP_SECRET`; otherwise stored
credentials may become unrecoverable and outstanding requests may be rejected.

### `SECRETS_KEYRING` and `SECRETS_ACTIVE_KEY_ID`

These keys wrap the random master keys used to encrypt site- and global-scope
secret values. The secret values themselves do not need to be decrypted and
re-encrypted during routine rotation; the CLI re-wraps their master keys.

Add the keyring before storing the first application secret. For rotation, add a
new independent key, retain the old key, and set the new ID active on every
instance. Run `iikiti:security:keys:rotate` (or the site-secrets-only
`iikiti:secrets:rotate-keys`), then remove the old key after successful
verification. Removing or losing a key before re-wrapping makes any master key
that depends on it unavailable, and therefore its stored secrets unreadable.

### `TRANSFER_CREDENTIALS_KEYRING` and `TRANSFER_CREDENTIALS_ACTIVE_KEY_ID`

These keys encrypt API credentials persisted for site-transfer jobs. Add them
before storing transfer credentials. Newly stored ciphertext records its key ID
and authenticates the associated job ID, so moving ciphertext between jobs or
using the wrong key fails decryption.

For rotation, configure old and new keys together, make the new ID active on all
instances, and run `iikiti:security:keys:rotate`. The command re-encrypts stored
rows and verifies the new ciphertext in the same database transaction as
site-secret master-key re-wrapping. If a required old key is missing or a row
cannot be authenticated, the transaction rolls back. Do not remove old keys
until the command succeeds and all instances have the same active configuration.
If an old key is lost before migration, affected credentials cannot be recovered;
the transfer must be re-authorized and its credential stored again.

Changing the active key without running the command does not invalidate rows
that still reference a configured old key, but removing that old key makes them
unreadable. Re-encryption cannot undo exposure of ciphertext and a compromised
old key that an attacker already copied; it protects future/current storage once
the old key is retired.

`TRANSFER_CREDENTIALS_ALLOW_LEGACY` controls reading rows written by the older
format encrypted from `APP_SECRET`. It defaults to `false`. For a migration that
has legacy rows, set it to `true` on all new-code instances, drain old application
instances so they cannot write more legacy ciphertext, run the rotation command,
then set it back to `false`. Changing `APP_SECRET` before this migration while
legacy reads are enabled makes those rows undecryptable.

### `GUEST_API_TOKEN_KEYRING` and `GUEST_API_TOKEN_ACTIVE_KEY_ID`

These keys sign short-lived, site-bound guest API tokens. Add the keyring before
serving requests that issue guest credentials. New tokens include a key ID.
When rotating, retain the old key for verification while making the new key
active consistently across instances. Keep the old key for at least five minutes
after the last instance that issued tokens with it has stopped; removing it
sooner rejects still-valid requests. Once all such tokens have expired, remove
the old key. There is no persisted data migration for these tokens.

`GUEST_API_TOKEN_ALLOW_LEGACY` controls acceptance of the old token format
signed with `APP_SECRET`; it defaults to `false`. Enable it only during a rolling
upgrade where old instances may still issue those tokens. Disable it after those
instances are drained and five minutes have elapsed. Losing or retiring a
guest-token key invalidates tokens signed by it, but clients can obtain new
tokens; it does not affect persistent credentials.

### `WIDGET_FRAGMENT_HMAC_KEY`

This independent key authenticates widget-fragment IDs used to request rendered
page fragments. Configure it before serving pages that use fragment rendering.
It is a single active key, not a keyring, because fragment IDs are temporary and
are not stored in the database.

Changing it makes outstanding fragment IDs fail validation. A page request
straddling a deployment or key change can therefore fail to load one or more
fragments; clients should refresh the page. Coordinate the new key across all
rendering instances before routing traffic to them. No database rotation command
is needed.

## Provisioning

Generate independent random 32-byte values through a secure operator workflow
and store them in the deployment's secret manager. Generate the default 32-byte,
standard-base64 value with:

```sh
php bin/console iikiti:security:generate-key
```

The command accepts `--bytes=64` when another purpose requires a different size;
use the size and encoding required by the specific key consumer. It prints only
the generated value to stdout and does not update `.env.local`, keyrings, or a
secret manager. Handle stdout as secret material: do not run it in CI jobs that
capture output, redirect it to a broadly readable file, include it in shell
commands or logs, or commit it. Store each independently generated value in the
deployment's secret manager (or in an ignored, owner-readable `.env.local` for
development).

## Password Generation

Generate a password locally with `php bin/console
iikiti:security:generate-password`. The command prints one password to stdout and
does not store it. Its `--strength` presets choose these default lengths and
character sets:

| Strength | Default length | Character classes |
| --- | ---: | --- |
| `weak` | 12 | lowercase letters and digits |
| `medium` | 20 | lowercase and uppercase letters, and digits |
| `strong` | 32 | lowercase and uppercase letters, digits, and symbols |
| `paranoid` | 64 | lowercase and uppercase letters, digits, and symbols |

`strong` is the default. Use `--length=48` to override a preset's length while
keeping its character classes, and `--no-symbols` when a receiving service does
not allow symbols. The generator ensures each selected character class appears
at least once and uses PHP's cryptographically secure random generator. Do not
choose a weaker preset or shorter length than the receiving service's policy
requires; handle command output as secret material and avoid capturing it in
logs or committed files.

Example configuration (use a different random value for every key):

```dotenv
SECRETS_ACTIVE_KEY_ID=2026-01
SECRETS_KEYRING='{"2026-01":"<base64-32-byte-key>"}'
TRANSFER_CREDENTIALS_ACTIVE_KEY_ID=2026-01
TRANSFER_CREDENTIALS_KEYRING='{"2026-01":"<different-base64-32-byte-key>"}'
TRANSFER_CREDENTIALS_ALLOW_LEGACY=false
GUEST_API_TOKEN_ACTIVE_KEY_ID=2026-01
GUEST_API_TOKEN_KEYRING='{"2026-01":"<another-base64-32-byte-key>"}'
GUEST_API_TOKEN_ALLOW_LEGACY=false
WIDGET_FRAGMENT_HMAC_KEY=<another-independent-base64-32-byte-key>
```

Use the application's ignored `.env.local` for development-only keys. Never copy
development keys into a production environment or reuse key material between
purposes. Keep access-controlled backups of old key material until all data
encrypted by it has been re-wrapped or re-encrypted and verification is complete.

## Rotation Workflow

Rotate when a key is suspected compromised, an operator or secret-management
boundary changes, or an organizational rotation policy requires it. Do not
rotate on every deployment without a reason; rotations require coordinated
configuration changes and, for persisted ciphertext, a database migration step.

The key-generation command only prints independent random material; it does not
update a deployment secret manager. Provision the new key there first, include
old and new keys, set the new ID active on every instance, and then run:

```sh
php bin/console iikiti:security:keys:rotate
```

This command transactionally re-wraps site/global secret master keys and
re-encrypts stored site-transfer credentials. It prints counts only. On failure,
it rolls back and reports no changes committed; keep the old keys configured,
resolve the cause, and retry. After success, verify the application can read the
stored values, confirm every instance uses the new active keys, then remove
retired site-secret and transfer-credential keys. `iikiti:secrets:rotate-keys`
remains available when only site-secret master keys need re-wrapping. Guest-token
and widget-fragment key changes are handled through their configured keyrings
and do not require this database command.
