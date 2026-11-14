# Response Header Management

The response-header API manages Application-wide and per-site policies. General
headers apply to Symfony responses, including redirects and errors, over both
HTTP and HTTPS, unless a rule's conditions exclude them. Apache-served static
files bypass Symfony and are not covered by these policies.

## Scope and Precedence

Application rules are either `default`, which site rules can override, or
`required`, which matching site rules cannot override. Within each category,
the highest-priority matching rule wins; list order breaks ties. Header names
are case-insensitive. A winning `remove` action removes the header rather than
falling back to a lower-priority rule. Only one value is emitted for each name.

Environment security defaults are the baseline. An Application default may
override one, a matching site rule may override that, and a matching required
Application rule wins over all site rules. Unrelated custom rules do not remove
security defaults. The standard security headers retain their existing security
mode gate, described below.

## API

- `GET` and `PUT /api/admin/applications/{applicationId}/headers` read or replace
  an Application policy. These require a globally assigned administrator role.
- `GET` and `PUT /api/site/{siteId}/headers` read or replace the current site's
  policy. These require a site-manager role for that site, or global admin.
- `POST` to either path plus `/preview` evaluates rules without saving them.

Use a user API token in `X-AUTH-TOKEN`. For a site API call, the path ID must
match the site resolved from the request host; `X-SITE-HOST` supplies the
site context when using the API through an internal origin. Writes replace a
whole versioned policy; read the current policy first and preserve unrelated
rules when updating it.

For example, an Application policy may require a robots directive on HTTPS
responses under `/private`:

```json
{
  "rules": [
    {
      "name": "X-Robots-Tag",
      "action": "set",
      "value": "noindex, nofollow",
      "priority": 10,
      "inheritance": "required",
      "conditions": {
        "all": [
          {"field": "scheme", "operator": "equals", "value": "https"},
          {"field": "path", "operator": "prefix", "value": "/private"}
        ]
      }
    }
  ]
}
```

Rules have `name`, `action` (`set` or `remove`), optional integer `priority`
(default 0), and optional `conditions`. A `set` rule has a string `value`;
`remove` has none. Application rules additionally accept `inheritance`
(`default` or `required`, defaulting to `default`); site rules must omit it.

Conditions are nested `all` and `any` lists or a single `not` condition.
Leaf predicates have `field` and `operator`: scheme and method support
`equals`; path supports `equals`, `prefix`, or `regex`; status supports
`equals` or `class` (a class value is an integer from 1 through 5); and header
supports `present`, `equals`, or `regex`, with a `name` field identifying the
request header. Regex values are raw patterns without delimiters. Pattern
length, match execution, tree depth, and policy size are bounded; executable
regex constructs and credential/cookie header conditions are rejected.
Transport, framing, cookie, cache, and framework-managed response headers are
reserved and cannot be configured. Equality comparisons are case-sensitive.
Policies are limited to 100 rules and 65,536 JSON bytes; a condition tree may
contain at most 64 nodes and eight levels. Header names are limited to 128
bytes, header values to 8,192 bytes, and regex patterns to 256 bytes. Regex
errors do not match, even under `not`. Responses vary on all request headers
referenced by rules, including rules that did not match.

For example, `POST /api/site/42/headers/preview` accepts:

```json
{
  "request": {
    "scheme": "https",
    "method": "GET",
    "path": "/private/page",
    "headers": {"X-Region": "eu"}
  },
  "status": 404
}
```

Preview returns the winning configured `rules` by normalized name, including
`remove` winners. Include an optional `rules` list to preview a replacement
without saving it. It does not simulate built-in security defaults or nonce
generation.

The old `/api/admin/sites/{siteId}/security/headers` routes are removed. The
data migration copies existing security policies into the new format, retaining
the original data and preserving the old replace-all behavior with explicit
removals for omitted security defaults. The migration stops and identifies the
site if an old policy contains values that fail the new safety checks; correct
the legacy policy before rerunning the migration.

## Security Headers

The built-in security set includes Content Security Policy (enforced and
report-only), HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
Permissions-Policy, Cross-Origin-Opener-Policy, Cross-Origin-Embedder-Policy,
and Cross-Origin-Resource-Policy. Defaults are stricter in production and
relaxed for the development toolbar.

`IIKITI_SECURITY_HEADERS=off` disables those security headers globally. With
the default environment setting, the site's `security_headers_mode` is
`default`, `forced_off`, or `forced_on`. Default mode emits the security set
only on secure, successful responses; forced-on includes HTTP and error
responses. General non-security headers are independent of this switch.

CSP and report-only CSP values may contain `__IIKITI_CSP_NONCE__`, replaced by
the per-response nonce; the HTML response listener adds the nonce to script and
style tags that lack one. CSP violation reports are accepted at
`POST /api/internal/csp-reports` and stored in `csp_reports`.

`X-Robots-Tag` is a normal general-header rule, not a separate SEO setting. A
future SEO administrative interface must modify the same stored rule; it is not
implemented here.

## Runtime Configuration

Configure `IIKITI_DEFAULT_APPLICATION_ID` with an existing Application ID for
requests whose host does not resolve to a site. Host-mapped site/Application
context takes precedence. The fallback is important for login, admin shell,
unmatched-host errors, and other site-independent responses. Deploy the new
code and run `php bin/console doctrine:migrations:migrate` before using the new
API; review any reported invalid legacy policy before retrying the migration.
For example, in `.env.local` set `IIKITI_DEFAULT_APPLICATION_ID` to the ID of
the Application that should handle otherwise unmapped requests. A missing or
invalid fallback returns 503 for requests without a resolvable Application.

For `APPLICATION_ROLE=consumer`, `INTERNAL_API_BASE_URL` must point to a
separate API runtime. Both runtimes must use the same `APP_SECRET` for the
authenticated internal header-resolution request. The consumer supplies the
original request context and final response status without forwarding user
credentials or cookies.
The API runtime only resolves hosts listed in `IIKITI_CONSUMER_HOSTS`
(comma-separated, for example `www.example.com,shop.example.com`); other hosts
receive 400. Set it to the public hosts that the consumer serves.

The consumer forwards only the fixed headers `Accept-Language`, `User-Agent`,
and `X-Device` for conditions. Rules may condition on other request headers
only if they are added to that allow-list; other headers are not forwarded.

The clocks of the two runtimes must be within 60 seconds. The internal request
accepts at most 100 headers, 4,096 bytes per header value, and 65,536 bytes of
JSON. If resolution fails, a successful response becomes 503 with `no-store`;
a non-success response (redirect, 4xx, 5xx) keeps its original status and body
with `no-store` added.
Streamed and file responses cannot have nonce attributes injected after output
is generated; their HTML content must already include the matching nonce when
using a nonce-based CSP.

Changes made directly in Apache or another reverse proxy are outside this API.
Protect and configure those layers separately where needed.
