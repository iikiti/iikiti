# Admin UI Extensibility

## Overview

Plugins can extend the admin UI by implementing the
`AdminExtensionInterface`. Services implementing this interface are
automatically tagged with `iikiti.admin.extension` and collected by the
`AdminMenuRegistry`.

## Implementing an Admin Extension

### 1. Create the extension class

```php
namespace Acme\Plugin\Blog\Admin;

use iikiti\CMS\Admin\AdminExtensionInterface;
use iikiti\CMS\ApiResource\AdminApiResource;
use iikiti\CMS\ApiResource\AdminMenuItem;

class BlogAdminExtension implements AdminExtensionInterface
{
    public function getMenuItems(): array
    {
        return [
            AdminMenuItem::create(
                label: 'Blog',
                path: '/blog',
                icon: 'edit-3',
                priority: 200,
                children: [
                    AdminMenuItem::create('All Posts', '/blog/posts'),
                    AdminMenuItem::create('Categories', '/blog/categories'),
                    AdminMenuItem::create('Tags', '/blog/tags'),
                ],
            ),
        ];
    }

    public function getResources(): array
    {
        return [
            AdminApiResource::create(
                name: 'posts',
                path: 'api_blog_posts_get_collection',
                label: 'Blog Posts',
                icon: 'edit-3',
            ),
        ];
    }
}
```

### 2. Register as a service

If your plugin uses auto-registration (standard for Symfony bundles), the
service is auto-tagged via the `#[AutoconfigureTag]` attribute on
`AdminExtensionInterface`.

Explicit registration (if auto-registration is disabled):

```yaml
# config/services.yaml in your plugin bundle
services:
    Acme\Plugin\Blog\Admin\BlogAdminExtension:
        tags: ['iikiti.admin.extension']
```

### 3. Provide API resources

Your plugin should also provide API Platform resources for any admin
endpoints you expose. These can be standard `#[ApiResource]` entities or
custom DTOs with providers/processors.

```php
#[ApiResource]
class BlogPostResource
{
    #[ApiResource\GetCollection(
        uriTemplate: '/admin/blog/posts',
        security: 'is_granted("ROLE_ADMIN") or object.can("BlogPost", "read")',
    )]
    public function getPosts(): array { ... }
}
```

## Menu Item Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `label` | string | — | Display text in the sidebar |
| `path` | string | — | Hash-based routing path (e.g. `/blog/posts`) |
| `icon` | string? | null | Icon identifier (rendered as emoji) |
| `badge` | string? | null | Optional badge text |
| `children` | list<AdminMenuItem> | [] | Submenu items |
| `priority` | int | 0 | Sort order (higher = first) |

## Menu Ordering

Menu items are sorted by `priority` (descending) within each extension.
Plugins should use priorities to position their sections:

| Range | Purpose |
|-------|---------|
| 1000+ | Core sections (reserved) |
| 500–999 | High-priority plugins |
| 100–499 | Standard plugins |
| 0–99 | Low-priority extensions |
| -100+ | Utility sections |

## Svelte 5 Route Components

The admin SPA uses hash-based routing. Plugin routes are automatically
registered when the admin app starts (by reading the menu and matching paths).

To provide a list view for your plugin's entity:

1. Create a Svelte component at
   `assets/svelte/admin/routes/BlogPosts.svelte`
2. The component receives `api` (ApiClient) and `debug` (boolean) as props
3. Fetch data from your plugin's API endpoints

```svelte
<script lang="ts">
    import { onMount } from 'svelte';
    import DataTable from '../components/DataTable.svelte';

    let { api, debug = false }: { api: any; debug?: boolean } = $props();
    let posts = $state(null);
    let loading = $state(true);

    const columns = [
        { key: 'id', label: 'ID' },
        { key: 'title', label: 'Title' },
        { key: 'status', label: 'Status' },
    ];

    onMount(async () => {
        loading = true;
        posts = await api.request('/admin/blog/posts');
        loading = false;
    });
</script>

<div>
    <h2 class="text-xl font-semibold mb-4">Blog Posts</h2>
    <DataTable data={posts} columns={columns} loading={loading} />
</div>
```
