# Audit Logging

## Overview

Every administrative and system action is recorded in the audit log for
compliance and debugging purposes. Each entry captures:

- **Who** performed the action (user ID or "system")
- **What** was done (action type)
- **What** was affected (object type + ID)
- **Before/after state** (for updates)
- **Request context** (IP, user agent, URI)
- **Additional context** (environment, debug info)

## Database Schema

Audit log entries are stored in the `audit_log_entries` table:

```sql
CREATE TABLE audit_log_entries (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    actor_type VARCHAR(32) NOT NULL DEFAULT 'user',
    action VARCHAR(32) NOT NULL,
    object_type VARCHAR(128) NOT NULL,
    object_id BIGINT UNSIGNED,
    before_state JSON,
    after_state JSON,
    context JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_uri TEXT,
    created_at TIMESTAMP NOT NULL
);
```

## What Gets Logged

### Automatic Logging (Doctrine Subscriber)

The `AuditSubscriber` hooks into Doctrine's `onFlush` event and automatically
records:

| Event | Action | Before State | After State |
|-------|--------|-------------|-------------|
| INSERT | `created` | null | Entity properties snapshot |
| UPDATE | `updated` | Changed fields only | Changed fields only |
| DELETE | `deleted` | Full entity snapshot | null |

### Explicit Logging (AuditLogger Service)

Specific admin actions are logged via the `AuditLogger` service:

| Action | Context |
|--------|---------|
| `assigned_role` | User role assignment |
| `removed_role` | User role removal |
| `joined_group` | User added to group |
| `left_group` | User removed from group |
| `updated_configuration` | Config changes |
| `audit_purged` | Audit log cleanup |

## Debug Mode

In debug environments (`dev`, `test`), additional context is captured:

```json
{
  "context": {
    "environment": "dev",
    "debug": true,
    "debug_info": {
      "trace": ["AuditSubscriber.php:68", "..."],
      "request_context": {
        "method": "POST",
        "query": {"page": "1"},
        "headers": { ... }
      }
    }
  }
}
```

Production environments only store the `environment` field for performance.

## Audit Log Viewer

Access the audit log viewer from the admin UI at **Audit Log** in the sidebar,
or via the API. The list returns top-level events; each carries its `subEvents`:

```
GET /api/admin/audit-logs
```

### Filter Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | int | Page number (1-based) |
| `itemsPerPage` | int | Items per page (max 100) |
| `actorType` | string | `user` or `system` |
| `action` | string | Action name (e.g. `created`, `updated`) |
| `objectType` | string | Object type (e.g. `User`, `Page`) |
| `actorId` | int | User ID |

### Example

```bash
curl -H "X-AUTH-TOKEN: <token>" \
  "http://localhost/api/admin/audit-logs?action=updated&objectType=User&page=1"
```

Response:

```json
{
  "hydra:member": [
    {
      "id": 125,
      "userId": 5,
      "actorType": "user",
      "action": "updated",
      "objectType": "User",
      "objectId": 12,
      "beforeState": {"roles": {"1": ["ROLE_MEMBER"]}},
      "afterState": {"roles": {"1": ["ROLE_EDITOR"]}},
      "context": {"environment": "prod"},
      "ipAddress": "192.168.1.100",
      "requestUri": "/api/users/12",
      "createdAt": "2026-09-19T14:30:00+00:00"
    }
  ],
  "hydra:totalItems": 1
}
```

## Performance

- Audit log inserts bypass the Doctrine change-tracking subscriber (excluded by
  `instanceof AuditLogEntry` check) to prevent recursion.
- The `audit_log_entries` table has indexes on `object_type`, `object_id`,
  `user_id`, and `created_at` for efficient filtering.
- Pagination is enforced on all audit log queries (default 25, max 100).

## Retention

Currently, audit log entries are retained indefinitely. A future enhancement
will add configurable retention policies via the `iikiti:admin:audit:cleanup`
command (to be implemented by plugins).

## Event Model (human summaries and sub-events)

The log is organised into **top-level events** and their **sub-events**:

- A top-level event is one human-readable action, for example
  "jimbo2150@gmail.com logged in from 10.0.0.5" or "Created API token for jimbo2150@gmail.com".
- Sub-events are the technical steps that made up that event (entity
  created/updated/deleted, token created/deleted, property deleted).
- Each request or CLI command opens one top-level event (`AuditRequestListener`).
  It is stored only if at least one change was recorded, so read-only requests
  leave no rows.
- Login and logout open their own top-level events (`SecurityAuditSubscriber`).
  API token creation during login is attached to the login event.
- The initiating user is stored as `user_id` plus a `username_snapshot`. The
  viewer resolves the live username first and falls back to the snapshot if the
  user has since been deleted.

Columns added: `parent_id` (self-reference, `NULL` for top-level events),
`summary`, `username_snapshot`. Migration `Version20261008130000`.

### Recording from code

Use `AuditRecorder` (see `docs/plugin-api.md`). Entries without a summary fall
back to a generated description; the list shows those with the action and object
type.

### Viewer

The Audit Log screen lists top-level events. Clicking a row opens the detail
dialog with the initiating user (name and id), the time, the action and object,
and every sub-event step.
