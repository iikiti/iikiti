# MFA workflow & usage

This document describes the multi-factor authentication flow implemented on top of
the project's **abstract workflow** engine (`src/Workflow/`), whose progression is
backed by Symfony Workflow runtime definitions.

## Overview

MFA is a workflow named `mfa_authentication`. Each factor is a *step* that
renders a Symfony form. The user is guided through the steps in a single
multi-step form; the Symfony multi-place marking and step context are persisted
between requests in the session. The site may use a versioned, admin-configured
graph; when none is assigned, `MfaStepProvider` builds the existing linear
factor sequence from the resolved policy. Factor verification remains in
registered code providers in both cases.

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
1. Resolves the current site workflow version and effective `MfaPreferenceResolver`
   policy, then loads the session marking or starts a challenge seeded with the
   user's enabled methods, e-mail, TOTP secret and backup-code hashes.
2. On `GET`: asks the current step to `prepare()` (this is where the e-mail code
   is generated and mailed), then renders its form.
3. On `POST`: submits the form; on a valid-but-rejected code the attempt limiter
   counts the failure and locks the user out after the configured number of
   tries.
4. Before setting the proxy token authenticated, checks every code-resolved
   required factor proof again against the current policy; only then redirects to
   the saved target (internal paths only).

### Steps (factors)
Located in `src/Workflow/Step/Mfa/`. Each extends `AbstractFormWorkflowStep`
and provides a form type from `src/Form/Type/Mfa/`:

| Step                  | Form type                | Factor                              |
| --------------------- | ------------------------ | ----------------------------------- |
| `EmailVerificationStep` | `EmailVerificationFormType` | 6-digit code mailed to the user   |
| `TotpVerificationStep`  | `TotpVerificationFormType`  | code from an authenticator app    |
| `BackupCodeStep`        | `BackupCodeFormType`        | single-use backup code (consumed) |

Factor steps are created by tagged `MfaFactorProviderInterface` services. The
code default runs one step per enabled method; an assigned MFA graph may reorder,
branch, or fork those registered factor steps. The core email, TOTP, and
backup-code providers record fixed proof keys
(`email_verified`, `totp_verified`, and `backup_code_used`). Plugins may add a
factor provider, but it must produce its own verified proof key; an admin graph
cannot set proof values itself.

The admin graph uses place metadata `mfaFactorKey` to refer to a registered
factor. `MfaGraphProofValidator` explores branch/fork/join markings for the
current user's enabled factors and refuses any terminal path that lacks one of
the policy-required proofs. At runtime, the `core.mfa_proof_gate` guard and the
controller's final proof check independently prevent a graph from authenticating
through an unverified path. Disabled per-user factors cannot be entered.

## The reusable multi-step form engine

The same mechanism powers any form flow, not only MFA.

- `WorkflowInterface` / `Workflow` — navigable steps, context, and legacy events
  (`src/Workflow/`); the adapter builds a Symfony Workflow graph and applies
  enabled transitions rather than mutating a step index.
- `FormStepInterface` / `AbstractFormWorkflowStep` — a step that renders a
  `FormType`.
- `WorkflowSessionStorage` + `WorkflowFactory` — persist/rebuild a workflow
  marking and context across requests; step objects and services are rebuilt,
  never serialized. MFA session state includes the selected workflow version and
  a policy fingerprint, not a durable `WorkflowInstance` row.
- `MultiStepFormRunner` — shared orchestration (`submitRequest()` returns a
  `FormSubmissionResult`).
- `MultiStepFormController` (`/forms/{workflow}`) — code/provider-defined form
  flows; `/forms/db/{workflow}` runs a site-assigned, version-pinned DB graph
  with session-backed markings.

### Dynamic (user-built) forms
`DynamicFormType`, `DynamicFormStep` and `WorkflowGraphStepFactory` build a form
from a field-definition list. The Workflows admin screen lets an authorized site
manager author a graph with safe form-place metadata, while the generic API
validates it against the same schema. For example, a workflow definition graph
contains:

```json
{
  "places": [
    { "id": "personal", "metadata": { "label": "Personal details", "form": {
      "fields": [
        { "name": "full_name", "type": "text", "label": "Full name", "required": true },
        { "name": "email", "type": "email", "label": "Email", "required": true }
      ]
    } } },
    "complete"
  ],
  "transitions": [{ "name": "finish_personal", "from": "personal", "to": "complete" }],
  "initialPlaces": ["personal"],
  "finalPlaces": ["complete"],
  "metadata": { "policyKey": "core.site_workflow" }
}
```

The generic routes `/forms/{workflow}` and `/forms/db/{workflow}` are gated by
`IS_AUTHENTICATED_FULLY`. MFA-specific graphs are selected by the site and persisted as immutable
definition versions. Definition updates create new versions; an in-flight MFA
challenge is discarded and restarted if either the graph version or effective
policy fingerprint changes.

DB form graphs are limited to 100 places, 200 transitions, 50 fields per form,
and 256 KiB. Field types/options and transition/provider keys are allowlisted;
arbitrary FormType FQCNs, service classes and expressions are rejected. The
legacy `/forms/{workflow}?steps=...` query definition is no longer accepted.

## Extending

- **New factor:** add a `MfaFactorProviderInterface` service (tagged
  `mfa.factor_provider`) that builds a form step and records a unique proof key.
  Add the factor key to the effective preference policy; graph definitions may
  reference only registered keys. Do not let a plugin or graph set the proof
  key directly.
- **New multi-step form:** register a `StepProviderInterface` (tagged
  `workflow.step_provider`) returning `AbstractFormWorkflowStep` instances for
  your workflow name, then drive it via a controller like
  `MultiStepFormController`.

## Security notes
- Failed MFA attempts are stored in the `cache.app` pool, keyed by a hash of the
  user identifier; discarding the session cookie does not reset them.
- E-mail codes are stored only as a hash and expire after `CHALLENGE_TTL` (300s).
- Backup codes are single-use and persisted only after their Symfony transition
  is accepted; the session tracks which hashed code was consumed so that this
  expected removal does not invalidate the challenge, while other backup-code
  policy changes do.
- MFA graph definitions never create durable workflow instances. TOTP secrets,
  backup-code hashes and submitted values stay in the session; workflow history
  and audit details never contain them.
- A graph-version/policy change, removed plugin factor provider, or changed
  effective factor set invalidates the challenge. Missing/invalid MFA policy
  fails closed and never authenticates the proxy token.
- Post-challenge redirects are restricted to internal paths.

## Verification
```
vendor/bin/phpunit --no-coverage
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run
php bin/console cache:clear --env=prod
```
