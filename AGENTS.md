# Project AGENTS.md for Kilo

> This is a *project-level* Kilo configuration document.
> It supplements (does not replace) the host/workspace `AGENTS.md`.
> See `.kilo/plans/` for active work plans.

## Changelog discipline

`CHANGELOG.md` at this repo's root records every notable change.
When Kilo makes a code/config change in this workspace, **append a dated entry**
(under the current work date, e.g. `- 2026-09-13: …`) to the matching section
(`Added`, `Changed`, `Removed`, `Fixed`, `Security`) within the `## [Unreleased]`
block before finishing the task.

- Prefer one concise line per changed behaviour.
- If the section does not yet exist, create it.
- Keep the `## [Unreleased]` heading as the top section; move its contents into a
  dated release block only as part of an explicit release step.

This rule is enforced as part of Kilo's own work-checklist for this workspace.

## Documentation

For changes made that require configuration changes or user interaction, make sure to add or update existing documentation under /docs and include description of the feature, a walkthrough of any relevant workflows, and installation or configuration steps required to be completed.

## API-First

Virtually all CMS data and actions should be available through the API in a secure way.