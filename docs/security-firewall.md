# Request Firewall

The request firewall evaluates bounded request data on the main `kernel.request`
event after site initialization and before controller execution. It complements
Symfony's authentication and authorization firewalls; it does not replace them.

The core request signatures detect common SQL injection, cross-site scripting,
and path traversal syntax. These signatures and downloaded rules report findings
by default. A matching rule can explicitly choose `deny`. Site policies are
additive: a site can add checks and deny matching requests, but cannot disable or
downgrade a global/core/plugin rule.

`iikiti.security` is dispatched once for each inspected main request, including
requests with no matches. `FirewallSecurityEvent` contains the sanitized finding
IDs/messages, effective `report`/`deny` action, and method/path/site/client-IP
summary. It never contains request bodies, headers, cookies, or upload contents.
Listeners can observe but cannot mutate the firewall decision. Listener
exceptions are logged and contained.

## Site Policy API

Site managers can read or replace the current site's custom rules:

- `GET /api/site/{siteId}/security/firewall/policy`
- `PUT /api/site/{siteId}/security/firewall/policy`

The caller must have `ROLE_SITE_MANAGER` for the current site; the path site ID
must also match the site selected from the request host. Global administrators
inherit this role. A policy is stored on its `Site` object and is limited to
1000 rules. Site rule IDs are local identifiers; the runtime namespaces them by
site ID so they cannot collide with global or downloaded rule IDs.
Site-authored rules are not evaluated on the firewall policy or global pack
status/activation operations, so a broad deny rule cannot lock administrators
out of correcting or rolling back policy. Core and downloaded global rules
continue to run on those operations.

Example policy:

```json
{
  "siteId": 4,
  "rules": [
    {
      "id": "suspicious-comment",
      "name": "Suspicious comment submission",
      "enabled": true,
      "action": "deny",
      "priority": 100,
      "conditions": {
        "all": [
          {
            "condition": "request.match",
            "options": {"target": "method", "operator": "equals", "value": "POST"}
          },
          {
            "any": [
              {
                "condition": "request.match",
                "options": {"target": "field", "name": "comment", "operator": "regex", "value": "<\\s*script\\b"}
              },
              {
                "condition": "request.match",
                "options": {"target": "upload", "operator": "contains", "value": "<script"}
              }
            ]
          }
        ]
      }
    }
  ]
}
```

Groups contain exactly one non-empty `all` (AND) or `any` (OR) list, and may be
nested to eight levels. Each rule is a top-level match; matching rules produce
findings, and any `deny` finding makes the effective action `deny`. Leaves name
a registered condition and supply data-only options. Rule trees are limited to
256 nodes, groups to 64 children, regexes to 4096 bytes, and the complete policy
to 1000 rules. Invalid or unknown conditions/options reject a policy update.

The built-in `request.match` condition supports targets `method`, `path`, `uri`,
`ip`, `header`, `cookie`, `query`, `field`, `body`, `upload`, `upload_name`, and
`upload_type`. Operators are `equals`, `contains`, `starts_with`, `ends_with`,
and `regex`; matching is case-insensitive unless `caseSensitive` is true. Named
targets accept `name`. Optional `transformations` run in order and are limited to
`lowercase`, `urlDecode`, `urlDecodeUni`, `htmlEntityDecode`, `removeNulls`, and
`normalizePath`.

## Request Limits And Responses

The firewall examines the request URL, method, client IP, headers, cookies,
query/form fields, raw non-multipart body, JSON fields, and complete uploaded
file contents. For multipart requests, body conditions include parsed fields
and file contents; PHP does not expose the original multipart framing to this
listener.

Configure deployment limits no higher than the web server/PHP limits:

| Setting | Default | Purpose |
|---------|---------|---------|
| `FIREWALL_MAX_INSPECTION_BYTES` | `8388608` | Maximum aggregate bytes inspected per request |
| `FIREWALL_MAX_UPLOADS` | `20` | Maximum files inspected per request |
| `FIREWALL_MAX_INSPECTION_MILLISECONDS` | `5000` | Request inspection and condition-evaluation budget |

JSON bodies larger than 1 MiB, nesting deeper than 32, over 10,000 values, an
unreadable upload, an over-limit request, or exhausted inspection budget are not
passed through: the firewall returns `413`. A rule configured as `deny` returns
`403`. A firewall evaluation/configuration failure returns a generic `503` and
is logged server-side. The firewall runs after PHP has received/spooled uploads;
web-server and PHP request-body limits remain necessary to protect that earlier
stage.

## Plugin Conditions And Events

Services implementing `FirewallConditionInterface` are tagged automatically.
The stable condition name is stored in rule JSON; never store a PHP class name
or expression in a policy. Validate options strictly and respect the shared
inspection deadline during long work:

```php
use iikiti\CMS\Security\Firewall\FirewallConditionInterface;
use iikiti\CMS\Security\Firewall\FirewallRequestContext;

final class AcmeRiskCondition implements FirewallConditionInterface
{
    public function getName(): string
    {
        return 'acme.risk';
    }

    public function validateOptions(array $options): void
    {
        if (array_diff(array_keys($options), ['marker']) !== [] || !is_string($options['marker'] ?? null)) {
            throw new InvalidArgumentException('Invalid Acme risk options.');
        }
    }

    public function matches(array $options, FirewallRequestContext $context): bool
    {
        $context->assertWithinBudget();
        foreach ($context->getValues('query') as $value) {
            if (str_contains($value, $options['marker'])) {
                return true;
            }
        }

        return false;
    }
}
```

Plugins may subscribe to the read-only event with a normal Symfony subscriber:

```php
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SecurityAuditSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [FirewallSecurityEvent::NAME => 'onSecurity'];
    }

    public function onSecurity(FirewallSecurityEvent $event): void
    {
        // Record finding IDs and the effective action; no raw request data is exposed.
    }
}
```

## Signed Rule Packs

Core and plugins register trusted sources by implementing
`FirewallRulePackSourceInterface`. The interface supplies a stable source key,
display name, fixed HTTPS envelope URL, and base64 Ed25519 public key. URLs are
not accepted from site policy/API data. Redirects are disabled and downloaded
responses have strict time and size limits.

An envelope is UTF-8 JSON with exactly these fields:

```json
{
	"source": "acme-crs",
	"version": "1.2.0",
  "format": "iikiti-json-v1",
  "sha256": "<lowercase SHA-256 of decoded payload bytes>",
  "signature": "<base64 Ed25519 signature>",
  "payload": "<base64 payload bytes>"
}
```

The detached signature covers the exact byte sequence
`iikiti-firewall-pack-v1\n{source}\n{version}\n{format}\n{sha256}\n{payload}`.
The decoded payload is limited to 8 MiB; the envelope is limited to 12 MiB.

`iikiti-json-v1` payloads have `{"schemaVersion":1,"rules":[...]}`. A
`modsecurity-seclang` payloads are syntax-checked using
`stardothosting/modsecurity-parser` and then converted into the same iikiti JSON
condition model. Parsing is not ModSecurity execution: the converter accepts
only the documented subset and rejects the complete pack if any directive,
variable, operator, transformation, action, or rule cannot be represented.
There is no best-effort skip mode and this is not full OWASP CRS compatibility.

Supported SecLang operators are `@rx`, `@contains`, `@streq`, `@beginsWith`, and
`@endsWith`. Supported variables are `REQUEST_URI`, `REQUEST_FILENAME`,
`REQUEST_METHOD`, `REMOTE_ADDR`, `REQUEST_HEADERS[:name]`,
`REQUEST_COOKIES[:name]`, `ARGS` (query and form fields), `ARGS_GET`,
`ARGS_POST`, `REQUEST_BODY`, and `FILES_TMP_CONTENT`. Multiple variables are OR
conditions; chained rules are AND conditions; independent rules remain
independent matches. Supported transformations are `t:none`, `t:lowercase`,
`t:urlDecode`, `t:urlDecodeUni`, `t:htmlDecode`, `t:removeNulls`, and
`t:normalizePath`. Supported metadata/actions are `id`, `phase` (1 or 2),
`msg`, `severity`, `tag`, `log`, `nolog`, `pass`, and explicit `deny` with
`chain`. SecLang `block` is rejected because its meaning depends on
`SecDefaultAction`. Includes, `SecAction`, default actions, response phases,
negated/count-based variables, stateful actions, and unsupported syntax reject
the whole pack.

Valid packs are converted and written as immutable version files before a
single atomic active pointer is switched. Invalid signatures, checksums,
versions, syntax, conversion, or IDs leave the prior active version unchanged.
Scheduled refresh accepts semantic versions and refuses automatic downgrades;
the explicit admin activation operation is the rollback path.
Previous versions remain in the protected `var/firewall/rule-packs/` directory.
Global administrators can reactivate a previously validated version with
`POST /api/admin/security/firewall/packs/{key}/activate` and body
`{"key":"acme-crs","version":"1.1.0"}`. Only content previously accepted
from a registered signed provider may be activated.

The core scheduled provider key is `security.firewall.pack_refresh`. Create a
**global** schedule from the Schedules admin screen with options
`{"sources":["acme-crs"]}`; omit/empty `sources` to refresh all registered
providers. Global schedules require a super/system administrator. Plugin source
providers are discovered after plugin activation and container rebuild. Pack
status is available to global admins at `GET /api/admin/security/firewall/packs`.

## Verification And Operations

Run focused tests with:

```sh
vendor/bin/phpunit tests/Security/Firewall
```

After adding/removing providers, rebuild the container and confirm the pack
source appears in the admin status API. Set initial custom actions to `report`,
inspect event findings, and only then opt individual site rules into `deny`.
