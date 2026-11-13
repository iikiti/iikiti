# MCP API

iikiti exposes its caller-facing API through one authenticated Model Context
Protocol endpoint. Core endpoints, API Platform operations, custom API routes, and
plugin APIs are cataloged behind three small MCP tools; plugins never create a
separate MCP server or endpoint.

## Endpoint and deployment

- **Endpoint:** `/api/mcp`
- **Transport:** MCP Streamable HTTP, provided by Symfony's MCP bundle and the
  official PHP MCP SDK.
- **Runtime:** API runtime only. The consumer runtime returns not found for this
  endpoint.
- **Authentication:** existing API token authentication; send the token in
  `X-AUTH-TOKEN`. The route requires `IS_AUTHENTICATED_FULLY`. Guest-render tokens
  are not accepted. Each dispatched API operation also runs its existing access
  checks, site restrictions, validation, and business logic.
- **Host protection:** `MCP_ALLOWED_HOSTS` is a comma-separated list of hostnames
  without ports. The development default includes `localhost`, `127.0.0.1`, and
  `iikiti.localhost`; configure the actual MCP hostnames for deployment rather
  than disabling DNS-rebinding protection.
- **Enablement:** MCP requests return not found unless `MCP_ENABLED=true`. Enable
  it only on the API runtime after API credentials, TLS/reverse-proxy policy, and
  allowed hosts are configured. Do not enable it on a consumer runtime.
- **Limits:** `MCP_API_MAX_PAGE_SIZE` defaults to 200 (the absolute maximum is
  200); `MCP_API_MAX_REQUEST_BYTES` defaults to 1 MiB;
  `MCP_API_MAX_RESPONSE_BYTES` defaults to 128 KiB. `api_list` defaults to 50
  entries per page. `MCP_API_RATE_LIMIT` defaults to 120 calls per minute per
  authenticated principal and site.
- **PHP requirement:** MCP's SDK requires `ext-fileinfo` in addition to the
  application's normal PHP extensions.

## Small MCP discovery surface

MCP protocol `tools/list` always contains only these three tools, independent of
the number of iikiti endpoints or plugins:

| MCP tool | Purpose |
|---|---|
| `iikiti.api_list` | Find caller-facing endpoint/function IDs. Supports namespace and text filters, cursor pagination, and a client-controlled `limit` from 1 to 200. The default page size is 50. Listing does not grant permission to invoke an endpoint. |
| `iikiti.api_describe` | Return a selected API's description, method/route or function kind, input/output JSON Schema where available, annotations, and authorization context. |
| `iikiti.api_request` | Invoke one exact catalog ID through the existing API route or registered function. It is not a general-purpose URL, SQL, shell, service-ID, or code execution tool. |

`api_list.limit` can be reduced or increased by the client. A configured server
cap may lower the maximum, and the request/response byte limits still apply.
Use the returned opaque `nextCursor` with the same filters to fetch the next
page. Restart without a cursor if a filter changes or a cursor is rejected.

## Endpoint/function catalog

The catalog is derived from the compiled Symfony API route collection. It
includes every caller-facing route under `/api/`, including API Platform
resource operations, custom core controllers, and routes contributed by loaded
plugins. API Platform operation descriptions and JSON Schemas are used when
available; custom API routes receive stable IDs and route-derived contracts.
`api_describe` returns path parameters, query parameters, JSON body schema, and
output schema where the source API publishes them. Existing API validation stays
authoritative.

Route IDs are stable and namespaced by owner. Core operations use IDs such as
`iikiti.core.read_users.get.admin_users_list`; write methods use a `write_` family
name and include the HTTP method. Plugin routes use
`iikiti.plugin.<plugin-slug>.<read-or-write>_<feature>.<method>.<route>`.
If a plugin adds a callable business function with no HTTP route, its local name
is prefixed with `iikiti.plugin.<plugin-slug>.`.

Authentication/session endpoints (`/api/auth/*`), internal worker routes
(`/api/internal/*`), API Platform error/validation handlers, and the MCP endpoint
itself are excluded. Plugin APIs are listed only while the owning plugin is
enabled for the current site. All other
API endpoints remain subject to their existing authorization rules; discovery
does not confer permission to call an endpoint.

The catalog exposes existing API behavior and does not invent unsupported
operations. For example, current editor settings routes write page/template
settings; they do not provide a general global/site settings read API. Such a
capability must first be added as a normal secure business API before it appears
in the MCP catalog.

## Calling an API

1. Call `iikiti.api_list` and note the exact `id` for the needed endpoint.
2. Call `iikiti.api_describe` with that ID when the required fields or effects
   are not already clear.
3. Call `iikiti.api_request` with the ID and endpoint-specific `arguments`.

For an HTTP route, `arguments` has these optional fields as applicable:

```json
{
  "pathParams": {"id": 42},
  "query": {"page": 1},
  "body": {"title": "Example"},
  "files": {
    "asset": {
      "filename": "example.png",
      "mimeType": "image/png",
      "contentBase64": "..."
    }
  }
}
```

Only the route selected by the exact catalog ID can be called. Path values are
passed to Symfony's router; query and JSON body values are forwarded to the same
in-process route as an authenticated subrequest, with the caller's host/site
context and security token. The site's host can be selected with the existing
`X-SITE-HOST` header, subject to the API's site authorization. Optional file
fields are bounded base64 uploads and are supported only when the target endpoint
accepts files. Binary responses are
returned base64-encoded. Oversized responses are marked truncated; a write that
completed but returned a truncated response must not be retried solely for that
reason.

For registered business functions, `arguments` matches the function's own input
schema from `api_describe`; no HTTP route is synthesized.

## Plugin functions

Routes under `/api/` from plugins are discovered automatically and share the
same catalog and dispatcher. A plugin should use its existing business APIs,
route security, and site checks. For callable functions that have no route,
implement `iikiti\CMS\Mcp\McpApiToolInterface` and register the service in the
plugin's standard service configuration. The interface requires a stable local
name, title, contextual description, explicit permission, input schema with a
description for each property, annotations, and an execution handler. The
registry applies the plugin's manifest namespace and checks the explicit
permission before execution. The function must still perform its business-level
authorization, validation, and audit behavior.

Do not add `#[McpTool]` methods in plugins. The bundle registry is restricted to
`iikiti\CMS\Mcp\ApiTools`, which contains the three wrappers. A plugin must not
create another MCP server, route, credential, or authentication flow.

## Security boundaries

- A caller must authenticate once to the shared MCP endpoint. All plugins use
  that identity; there are no plugin-specific MCP credentials.
- Namespaces identify ownership, not permission. Each target route's existing
  authorization and site checks decide whether an operation can run.
- Custom functions declare a Symfony authorization attribute/role and must
  delegate to existing business services. A tag or plugin enablement alone does
  not grant access.
- The dispatcher never accepts arbitrary client-provided URLs or PHP service
  names. It resolves the ID against the compiled catalog and uses the route name
  stored in that entry.
- Login, MFA, session-token issuance, worker callbacks, and the MCP endpoint are
  not callable catalog entries.
- MCP tool annotations communicate read/write/destructive hints; they do not
  replace permission checks or explicit confirmation required by an API.
- Do not place API tokens, passwords, MFA proofs, or secret values in tool
  descriptions, input defaults, logs, or example arguments.

## Verification

```sh
MCP_ENABLED=true php bin/console debug:mcp
MCP_ENABLED=true php bin/console debug:router --show-controllers
vendor/bin/phpunit tests/Mcp tests/Integration/McpApiRegistryWiringTest.php
```

`debug:mcp` should report exactly `iikiti.api_list`, `iikiti.api_describe`, and
`iikiti.api_request`. Check that `/api/mcp` is enabled only in the API runtime and
rejects anonymous requests; then use a least-privilege user API token to verify
that `api_list`, `api_describe`, and an authorized read and write call respect
existing API permissions.
