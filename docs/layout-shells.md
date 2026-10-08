# Layout shells

Layout shells are the header, footer, sidebars and dialogs of a site. They are
global objects (type `Shell`), not part of a single template. Only the main
content region belongs to a template.

## Roles

| Role     | Renders as                 |
|----------|----------------------------|
| `header` | site header                |
| `footer` | site footer                |
| `aside`  | sidebar (left or right)    |
| `dialog` | dialog markup              |

A role with no matching shell emits no markup at all.

## Display rules

A shell has `display_rules`: a list of `{ "rule": "<name>", "config": {...} }`.
A shell shows on a request when it is enabled and at least one rule matches.
Rules come from the same registry templates use (`iikiti.cms.template_rule`), so a
plugin that adds a template rule also works for shells. Built-in rules: `site`,
`object`, `object_type`.

Example: a footer for site 1 only.

```json
[{ "rule": "site", "config": { "site_id": 1 } }]
```

## Ordering

All matching shells of a role render, in ascending `priority` order. Ties keep
stored order.

## Validation

On save, `ShellValidator` rejects a shell when:
- the role is unknown,
- a root block is not a `container` (same rule as template regions),
- a display rule names a rule that is not registered.

## Managing shells

- **Editor:** the Layout toolbar button opens the Layout dialog to add, edit,
  enable or delete shells grouped by role.
- **Admin:** Templates > Layouts lists and edits shells. Admin access is required.

## Plugin rule registration

Implement `TemplateRuleInterface` and register the service. It is auto-tagged
with `iikiti.cms.template_rule`:

```php
final class LanguageRule implements TemplateRuleInterface
{
    public function getName(): string { return 'language'; }

    public function matches(array $config, TemplateResolutionContext $context): bool
    {
        return ($config['code'] ?? null) === $this->currentLanguage();
    }
}
```

## Migration from template regions

`Version20261008120000` converts each template's non-main region with blocks into a
shell scoped to that template's site. Regions with no blocks are skipped, and
templates without a site or creator are skipped rather than written invalidly.
