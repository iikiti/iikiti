# MFA workflow & usage

This document describes the multi-factor authentication flow implemented on top of
the project's **abstract workflow** engine (`src/Workflow/`).

## Overview

MFA is a workflow named `mfa_authentication`. Each factor is a *step* that
renders a Symfony form. The user is guided through the steps in a single
multi-step form; the workflow state is persisted between requests in the session.

```
login (form_login)
   -> token wrapped in AuthenticationToken (unauthenticated)
   -> AccessHandler redirects to /mfa/challenge
   -> MfaChallengeController drives the mfa_authentication workflow
   -> challenge passes  -> token flipped to authenticated
   -> user redirected to the originally requested page
```

MFA is opt-in per user via preferences: a user must have
`mfa.enabled = true` and a non-empty `mfa.methods` list (e.g. `['email']`,
`['totp']`, `['email', 'totp', 'backup_code']`). Users without these
preferences log in exactly as before.

## Configuration

`config/packages/mfa.yaml`:

| Parameter             | Default            | Description                              |
| --------------------- | ------------------ | ---------------------------------------- |
| `mfa.sender_email`    | `no-reply@localhost` (or `MFA_SENDER_EMAIL`) | "From" address for verification codes. |
| `mfa.max_attempts`    | `5`                | Failed attempts before lockout.          |
| `mfa.lockout_seconds` | `300`              | Lockout window length in seconds.        |

A real mail transport must be configured (`MAILER_DSN`), otherwise sending a
code fails loudly rather than being silently dropped.

## How it works

### Triggering MFA
`src/Authentication/Event/Subscriber/AuthenticationTokenSubscriber.php` listens to
`AuthenticationTokenCreatedEvent`. After a credential-based login it consults
`MfaPreferenceResolver` (which merges `APPLICATION` / `SITE` / `USER` preferences
via `MfaConfigurationServiceInterface`); when MFA is required it replaces the login
token with an `AuthenticationToken` proxy that reports `isAuthenticated() === false`
and exposes no roles. (Credential-less authenticators such as the API token are
left untouched.)

### Access handling
`src/Security/AccessHandler.php` turns an access denial for an unauthenticated MFA
proxy into a redirect to `/mfa/challenge`, remembering the originally requested
page in the session via `TargetPathStorage`.

### The challenge flow
`src/Controller/Page/MfaChallengeController.php`:
1. Loads the persisted workflow, or starts one seeded with the user's methods,
   e-mail, TOTP secret and backup codes.
2. On `GET`: asks the current step to `prepare()` (this is where the e-mail code
   is generated and mailed), then renders its form.
3. On `POST`: submits the form; on a valid-but-rejected code the attempt limiter
   counts the failure and locks the user out after the configured number of
   tries.
4. On the last step succeeding: flips the proxy token to authenticated and
   redirects to the saved target (internal paths only).

### Steps (factors)
Located in `src/Workflow/Step/Mfa/`. Each extends `AbstractFormWorkflowStep`
and provides a form type from `src/Form/Type/Mfa/`:

| Step                  | Form type                | Factor                              |
| --------------------- | ------------------------ | ----------------------------------- |
| `EmailVerificationStep` | `EmailVerificationFormType` | 6-digit code mailed to the user   |
| `TotpVerificationStep`  | `TotpVerificationFormType`  | code from an authenticator app    |
| `BackupCodeStep`        | `BackupCodeFormType`        | single-use backup code (consumed) |

Steps are produced by `MfaStepProvider` from the workflow context (one step per
enabled method).

## The reusable multi-step form engine

The same mechanism powers any form flow, not only MFA.

- `WorkflowInterface` / `Workflow` — navigable steps, context, events
  (`src/Workflow/`).
- `FormStepInterface` / `AbstractFormWorkflowStep` — a step that renders a
  `FormType`.
- `WorkflowSessionStorage` + `WorkflowFactory` — persist/rebuild a workflow
  across requests.
- `MultiStepFormRunner` — shared orchestration (`submitRequest()` returns a
  `FormSubmissionResult`).
- `MultiStepFormController` (`/forms/{workflow}`) — generic driver; it accepts a
  JSON-encoded `steps` query parameter to start a **dynamic** workflow.

### Dynamic (user-built) forms
`DynamicFormType`, `DynamicFormStep` and `DynamicFormStepProvider` build a form
from a field-definition list, so an administrator will eventually be able to create
a workflow by supplying:

```json
{
  "steps": [
    {
      "id": "personal",
      "name": "Personal details",
      "fields": [
        { "name": "full_name", "type": "Symfony\\Component\\Form\\Extension\\Core\\Type\\TextType" },
        { "name": "email", "type": "Symfony\\Component\\Form\\Extension\\Core\\Type\\EmailType" }
      ]
    }
  ]
}
```

The generic route `/forms/{workflow}` is gated by `IS_AUTHENTICATED_FULLY`. A
future persistence layer only needs to seed the same `steps` context (via a new
`StepProviderInterface` or a start controller) — the rendering/navigation layers
are unchanged.

## Extending

- **New factor:** add a `AuthenticationStrategyInterface` (or reuse an existing
  strategy), a `FormType`, and a step under `src/Workflow/Step/Mfa/`. Register
  the method name in `MfaUserConfiguration` and surface it through user
  preferences.
- **New multi-step form:** register a `StepProviderInterface` (tagged
  `workflow.step_provider`) returning `AbstractFormWorkflowStep` instances for
  your workflow name, then drive it via a controller like
  `MultiStepFormController`.

## Security notes
- Failed MFA attempts are stored in the `cache.app` pool, keyed by a hash of the
  user identifier; discarding the session cookie does not reset them.
- E-mail codes are stored only as a hash and expire after `CHALLENGE_TTL` (300s).
- Backup codes are single-use and persisted on consumption.
- Post-challenge redirects are restricted to internal paths.

## Verification
```
vendor/bin/phpunit --no-coverage
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run
php bin/console cache:clear --env=prod
```
