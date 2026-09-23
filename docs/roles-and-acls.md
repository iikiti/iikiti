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
    # Core role chain (explicit inheritance)
    ROLE_NON_MEMBER: []
    ROLE_USER: [ROLE_NON_MEMBER]
    ROLE_MEMBER: [ROLE_USER]
    ROLE_AUTHOR: [ROLE_MEMBER]
    ROLE_EDITOR: [ROLE_AUTHOR]
    ROLE_MANAGER: [ROLE_EDITOR]
    ROLE_SITE_MANAGER: [ROLE_MANAGER]
    ROLE_ADMIN: [ROLE_SITE_MANAGER]
    ROLE_SUPER_ADMIN: [ROLE_ADMIN]
    ROLE_SYSTEM: [ROLE_SUPER_ADMIN]

    # Wildcard patterns (Symfony 8.2 native) — pattern keys grant parent
    # roles to any matching dynamic or database-defined role.
    'ROLE_*': [ROLE_USER]
    'ROLE_PLUGIN_*': [ROLE_ADMIN]
    'ROLE_SITE_*': [ROLE_SITE_MANAGER]
    'ROLE_CONTENT_*': [ROLE_EDITOR]
    'ROLE_MOD_*': [ROLE_MODERATOR]
    'ROLE_BLOG_*': [ROLE_BLOG_READER]
    'ROLE_*_MODERATOR': [ROLE_MODERATOR]
    'ROLE_MEMBER_*': [ROLE_MEMBER]
    'ROLE_AUTHOR_*': [ROLE_AUTHOR]
    'ROLE_EDITOR_*': [ROLE_EDITOR]
    'ROLE_MANAGER_*': [ROLE_MANAGER]
    'ROLE_USER_*': [ROLE_USER]
```

### Wildcard Role Hierarchy

Symfony 8.2 introduces native wildcard support in the role hierarchy. Wildcard
patterns are used as **keys** (never as parent-role values). The `*` character
is only treated as a wildcard when:

- Wrapped by underscores: `ROLE_*_MODERATOR`
- Placed after an underscore at the end: `ROLE_BLOG_*`

Keys like `ROLE_BLOG*` (no underscore before `*`) are treated as literal role
names, not wildcards.

#### How wildcards work

A pattern key grants its listed parent roles to **any** role that matches the
pattern — including roles not explicitly listed in the configuration. For
example, `ROLE_PLUGIN_*` grants `ROLE_ADMIN` to every role matching that
pattern, so a user with `ROLE_PLUGIN_SEOPACK` automatically inherits all
permissions from `ROLE_ADMIN` and below.

Roles don't need to be listed in the hierarchy to match a wildcard. This means
dynamically-registered plugin roles and database-defined `Role` entities are
matched automatically at runtime.

#### Compile-time hierarchy construction

The hierarchy is built at container compile time by
`DynamicRoleHierarchyPass`, which uses the `DynamicRoleHierarchy` class as the
single source of truth. This class defines both the static core chain and the
wildcard patterns as structured PHP constants (not just YAML), enabling:

- Validation of the hierarchy structure
- Programmatic introspection
- Unit testing without a running container

The compiler pass sets the `security.role_hierarchy.roles` parameter, which
Symfony's native `RoleHierarchy` reads to resolve role inheritance.

## Debugging the Role Hierarchy

Symfony 8.2 provides the native `debug:roles` command for inspecting the role
hierarchy, including wildcard expansion:

```bash
# Full hierarchy with all reachable roles
php bin/console debug:roles

# Detailed reachable roles for a specific role
php bin/console debug:roles ROLE_MANAGER

# Tree view showing why each role is granted (including wildcard matches)
php bin/console debug:roles ROLE_MANAGER --tree
```

The Security panel of the Symfony profiler also displays the role hierarchy as
a graph diagram.

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

### Role Naming Convention

All role values must follow the pattern `ROLE_[A-Z0-9_]+` — they must start with
`ROLE_` and contain only uppercase letters, digits, and underscores. This
convention is enforced at registration time in `UserRoleEnum::register()`.

### Dynamic Role Registration

Plugins can register new roles at boot time:

```php
// In your plugin's boot/initialize method
UserRoleEnum::register('Publisher', 'ROLE_PUBLISHER');
```

### Scoped Dynamic Roles

Roles that follow a scoped naming pattern (e.g. `ROLE_PLUGIN_*`, `ROLE_SITE_*`)
automatically inherit permissions from their tier's parent role via the
wildcard hierarchy:

| Naming Pattern | Inherits From | Description |
|----------------|---------------|-------------|
| `ROLE_PLUGIN_*` | `ROLE_ADMIN` | Plugin-specific roles get full admin access |
| `ROLE_SITE_*` | `ROLE_SITE_MANAGER` | Site-specific roles inherit site management permissions |
| `ROLE_CONTENT_*` | `ROLE_EDITOR` | Content-scoped roles inherit editor permissions |
| `ROLE_MOD_*` | `ROLE_MODERATOR` | Moderation roles |
| `ROLE_BLOG_*` | `ROLE_BLOG_READER` | Blog-specific roles |
| `ROLE_*_MODERATOR` | `ROLE_MODERATOR` | Suffix-pattern moderator roles |

For example, registering `UserRoleEnum::register('SEO Pack', 'ROLE_PLUGIN_SEOPACK')`
gives the `ROLE_PLUGIN_SEOPACK` role all permissions of `ROLE_ADMIN` (and all
roles below it in the hierarchy) without explicitly listing them.

### Creating Role Entities

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
