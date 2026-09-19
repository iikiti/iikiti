# Roles & Access Control Lists (ACLs)

## Default Roles

The system includes 8 default roles. System-defined roles (`is_default = true`)
**cannot be deleted**. Default permissions are **immutable** — only custom
permissions can be added, modified, or removed.

| Role | Symfony Value | System-Protected | Hidden (default) | Description |
|------|---------------|------------------|-------------------|-------------|
| **System** | `ROLE_SYSTEM` | Yes | No | Non-user-based actions by the application |
| **Admin** | `ROLE_ADMIN` | Yes | No | Full administration access |
| **Site Manager** | `ROLE_SITE_MANAGER` | Yes | No | Manage sites and content within sites |
| **Manager** | `ROLE_MANAGER` | Yes | No | Manage pages and view users |
| **Editor** | `ROLE_EDITOR` | Yes | No (toggleable) | Edit and publish pages |
| **Author** | `ROLE_AUTHOR` | Yes | No (toggleable) | Create and edit own pages |
| **Member** | `ROLE_MEMBER` | Yes | No | Registered user access |
| **Non-Member** | `ROLE_NON_MEMBER` | Yes | No | Anonymous/unauthenticated access |

### Legacy Compatibility

The following role values are also registered in `UserRoleEnum` for backward
compatibility but do **not** have Role entities in the database:

| Legacy Name | Value | Maps To |
|-------------|-------|---------|
| User | `ROLE_USER` | Alias for Member (in role hierarchy) |
| Super Administrator | `ROLE_SUPER_ADMIN` | Alias for System (in role hierarchy) |

## Role Hierarchy

Roles are organized hierarchically. Each role inherits all permissions of
roles below it:

```
System → Super Administrator → Admin → Site Manager → Manager → Editor → Author → Member → User → Non-Member
```

In `config/packages/security.yaml`:

```yaml
role_hierarchy:
    ROLE_SYSTEM: [ROLE_SUPER_ADMIN]
    ROLE_SUPER_ADMIN: [ROLE_ADMIN]
    ROLE_ADMIN: [ROLE_SITE_MANAGER]
    ROLE_SITE_MANAGER: [ROLE_MANAGER]
    ROLE_MANAGER: [ROLE_EDITOR]
    ROLE_EDITOR: [ROLE_AUTHOR]
    ROLE_AUTHOR: [ROLE_MEMBER]
    ROLE_MEMBER: [ROLE_USER]
    ROLE_USER: [ROLE_NON_MEMBER]
    ROLE_NON_MEMBER: []
```

## Permission Model

Each role has two permission sets:

### Default Permissions (Immutable)

Defined at creation time and stored in the `default_permissions` field of the
`roles` table. These **cannot be modified by anyone**, including
administrators.

#### Example default permissions for Admin role:

```json
{
  "user": ["read", "write", "delete"],
  "user_group": ["read", "write", "delete"],
  "application": ["read", "write", "delete"],
  "site": ["read", "write", "delete", "configure"],
  "site_group": ["read", "write", "delete"],
  "role": ["read", "write"],
  "page": ["read", "write", "delete", "publish"],
  "lexeme": ["read", "write", "delete"],
  "plugin": ["read", "write", "install", "remove"],
  "search": ["read", "write", "rebuild", "configure"],
  "audit_log": ["read"]
}
```

#### Example default permissions for Non-Member role:

```json
{
  "search": ["read"]
}
```

### Custom Permissions (Editable)

Stored in the `custom_permissions` field. Administrators can add, modify, or
remove these through the admin UI (`/api/admin/roles/{id}` PUT). Custom
permissions are merged with defaults at evaluation time.

#### Example: Adding a custom permission to the Editor role

```json
{
  "custom_permissions": {
    "application": ["read"]
  }
}
```

This gives the Editor role read access to applications in addition to its
default page permissions.

## ACL (User Group Permissions)

User groups can have ACL permissions stored in the `permissions` property on
the group's DbObject. These provide fine-grained, object-type-level access
control on top of role-based permissions.

**Permission format:**

```json
{
  "objectType": {
    "objectId_or_*": ["action1", "action2"]
  }
}
```

### Example ACL on a UserGroup

```json
{
  "application": {
    "1": ["read", "write"],
    "2": ["read"]
  },
  "user": {
    "*": ["read"]
  },
  "*": {
    "*": ["read"]
  }
}
```

This ACL grants:
- Read+write on application #1
- Read-only on application #2
- Read-only on all users
- Read-only on everything else

## Permission Resolution

When checking if a user has permission to perform an action:

1. **System override**: If the user has `ROLE_SYSTEM`, access is always granted.
2. **Role-based**: Check the user's roles against Role entities' permissions
   (merged default + custom). Uses wildcard matching (`*` for any object type
   or any action).
3. **Group-based**: Check the user's group memberships against each group's
   ACL permissions. Supports object-specific (`*`) and wildcard object IDs.
4. **Default deny**: If none of the above grant access, deny.

### Example permission check

```php
// Check if a user can write to the Page entity type
if ($permissionChecker->canAccess($user, 'Page', 'write')) {
    // Allow
}
```

## API Reference

### GET /api/admin/roles

Returns all roles with their default and custom permissions.

```json
[
  {
    "id": 1,
    "name": "System",
    "value": "ROLE_SYSTEM",
    "isDefault": true,
    "isDeletable": false,
    "isHidden": false,
    "defaultPermissions": {"*": ["*"]},
    "customPermissions": {},
    "allPermissions": {"*": ["*"]}
  }
]
```

### PUT /api/admin/roles/{id}

Update custom permissions or toggle `is_hidden` (only on non-system roles).

```json
{
  "customPermissions": {
    "profile": ["read", "write"]
  },
  "isHidden": false
}
```

### POST /api/admin/user-groups

Create a user group with ACL permissions.

```json
{
  "name": "content-editors",
  "label": "Content Editors",
  "description": "Editors with extended permissions",
  "permissions": {
    "page": {
      "*": ["read", "write", "publish"]
    }
  }
}
```

## Registering Custom Roles

Plugins can register new roles at boot time:

```php
// In your plugin's boot/initialize method
UserRoleEnum::register('Publisher', 'ROLE_PUBLISHER');
```

To make a role non-deletable with default permissions, create a Role entity
record (typically via a migration):

```php
$role = new Role('Publisher', 'ROLE_PUBLISHER', [
    'page' => ['read', 'write', 'publish'],
]);
$role->setHidden(false);
// isDefault and isDeletable are set by the migration seeding
$entityManager->persist($role);
$entityManager->flush();
```
