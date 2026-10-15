# Workflow Definitions and Runtime

The CMS uses Symfony Workflow in three modes:

- Code-owned subject lifecycles use attribute-defined state machines (`src/StateMachine/`), such as the aggregate `asset_processing` status.
- Interactive form flows use the B adapter in `src/Workflow/`; Symfony markings and transitions live in the user session, while step/form data and step implementations are rebuilt per request.
- Admin/plugin-defined durable workflows compile immutable database graph versions into Symfony `workflow` definitions. Their marking/context, task assignments, and transition history are stored in the workflow tables.

All three use Symfony Workflow transitions; the interactive and durable graph modes use the multi-marking `workflow` semantics for branches and parallel fork/join. The `#[AsWorkflow]` attribute is for static code definitions, not database graph loading.

## Admin UI and authorization

Open the admin SPA's **Workflows → Definitions** screen to create/edit site-owned graphs, inspect versions, assign shared definitions, or fork a core/plugin definition. **Workflows → Task inbox** shows assigned direct-user and site-role tasks. The graph editor persists a bounded JSON graph and displays registered policy, action, assignment, MFA-factor, and asset-processor keys.

- Global admins can create core definitions and assign shared definitions to one or more sites.
- Site managers can create/edit definitions owned by their site and perform workflow operations within sites they manage. They cannot mutate shared core or plugin source graphs; they must create a site-owned fork first.
- Plugin-owned definitions are synchronized from tagged providers, assigned to sites, or forked by a site manager.
- A site assignment is not itself transition authorization. Start, task claim, and transition operations also run the workflow policy and subject/task scope checks.
- The admin SPA API uses the current authenticated user's API token (`X-AUTH-TOKEN`). Generic `/api` authorization is per operation; do not assume that the firewall alone protects a resource.

## Graph document

The normalized graph document has a bounded schema:

```json
{
  "type": "workflow",
  "places": [
    { "id": "review", "metadata": { "label": "Review" } },
    { "id": "approved", "metadata": { "label": "Approved" } },
    { "id": "rejected", "metadata": { "label": "Rejected" } },
    { "id": "complete", "metadata": { "label": "Complete" } }
  ],
  "transitions": [
    { "name": "approve", "from": "review", "to": "approved" },
    { "name": "reject", "from": "review", "to": "rejected" },
    { "name": "finish_approved", "from": "approved", "to": "complete" },
    { "name": "finish_rejected", "from": "rejected", "to": "complete" }
  ],
  "initialPlaces": ["review"],
  "finalPlaces": ["complete"],
  "metadata": { "mode": "durable", "policyKey": "core.site_workflow" }
}
```

Transitions have globally unique names. Put all sources/targets for one join/fork in a single transition's `from`/`to` arrays; a fork may create several active markings and a join requires all its source markings. Final places have no outgoing transitions; retryable failure/review states should be non-final places with explicit retry arcs. Mark a transition with `metadata.automatic: true` for a trusted automatic join/continuation. Automatic transitions are never accepted as a user-supplied transition; the runtime drains them with a 32-transition limit after a successful action.

Limits: 100 places, 200 transitions, 256 KiB per graph, 50 fields per form place, and bounded metadata/options. Graph validation rejects unknown places/properties, duplicate names, unreachable references, invalid initial/final markings, unsafe form types/options, unregistered provider keys, dead ends, and incompatible task/join definitions. Compiled definitions are additionally checked with Symfony's own `WorkflowValidator` before a runtime `Workflow` is constructed. No graph data is evaluated as PHP, an arbitrary class name, raw SQL, or an unrestricted expression.

## Form and task places

A session-backed interactive form place uses safe `form.fields` metadata:

```json
{
      "id": "details",
      "metadata": {
        "label": "Request details",
        "humanTask": { "roleKey": "ROLE_SITE_MANAGER", "label": "Review request details" },
        "form": {
      "fields": [
        { "name": "title", "type": "text", "label": "Title", "required": true },
        { "name": "priority", "type": "choice", "label": "Priority", "required": true,
          "options": { "choices": { "normal": "Normal", "urgent": "Urgent" } } }
      ]
    }
  }
}
```

Supported registered types are `text`, `textarea`, `email`, `number`, `choice`, and `checkbox`. The server rebuilds Symfony Forms and validates required fields and allowed options on every transition; browser validation is not trusted. Admin-authored interactive graphs are started at `/forms/db/{workflowKey}`; the session pins the graph version and marking. The legacy `/forms/{workflow}?steps=...` query-JSON definition has been removed. MFA has a dedicated factor-provider graph adapter and never uses the generic task inbox.

A durable human-task place adds `humanTask` metadata. Exactly one target is required: a direct `userId`, a `roleKey` within the instance's site, or a registered `assignmentKey` resolver. Role tasks appear in eligible members' site inboxes and can be claimed or released; only the claimant (or an authorized site/global manager) can complete them. Parallel task places materialize separate task rows. Do not put a multi-place join over human/form places: complete each task's single-source transition first, then join the non-human branch-completion places.

Definitions use `metadata.mode: "durable"` by default. A durable form place must also be a human task with an assignment target, so every persisted form has a task inbox entry. Use `metadata.mode: "session"` for a single-user interactive form flow started at `/forms/db/{workflowKey}`; session-mode definitions have no durable task assignments and cannot be started through the durable instance API. MFA graphs are forced to session mode and the core proof policy.

## Persistence, versions, and subjects

- `workflow_definitions` records owner (`core`, `site`, `plugin`), key, enabled/archive state, current version, and site assignments.
- `workflow_definition_versions` stores immutable, validated graph snapshots plus policy/source metadata.
- `workflow_instances` stores the pinned version, optional registered subject type key + opaque ID, JSON marking/context, execution state, idempotency key, attempts/error, and optimistic lock version.
- `workflow_tasks` records active human-place tokens, direct user/role target, claimant, and task state. Instance/task changes use optimistic locking and database transactions.
- `workflow_instance_events` is append-only transition/task lifecycle history. Details must not contain submitted form secrets or MFA material.

Edits create new immutable versions. Existing durable instances continue using their pinned version; archiving a definition blocks new starts but does not remove an active instance's graph. Plugin updates include the provider source version in the definition version hash. Instances that reference code no longer available pause with a visible reason; reactivation resumes them when their pinned handler/policy/assignment keys are registered again.

Subjects are resolved only by a registered `WorkflowSubjectResolverInterface` key; graph/request data never names an entity class. Standalone workflows may omit the subject and use bounded context JSON. Keep MFA secrets and proofs in the session-only MFA workflow, never in `workflow_instances.context`.

## Plugin extension points

Implement and register these tagged interfaces in an active plugin bundle:

- `WorkflowDefinitionProviderInterface`: returns `WorkflowDefinitionData` and a `getSourceVersion()`; use the plugin manifest ID as owner key. Active provider definitions are persisted by `iikiti:workflow:sync-definitions` after plugin activation/update and container rebuild.
- `WorkflowPolicyInterface`: a required, stable policy key for a plugin definition plus any transition `guardKeys`. Missing keys fail closed.
- `WorkflowTransitionActionInterface`: trusted transition effect. It executes within the workflow DB transaction; schedule external I/O via Messenger.
- `WorkflowSubjectResolverInterface`: resolves a registered subject key + ID and checks site access.
- `WorkflowTaskAssignmentResolverInterface`: resolves a human-place target to one user or one role.
- `MfaFactorProviderInterface`: registered form step, proof key, provider version, and per-user/site availability. The core MFA proof gate is not replaceable.

Example provider:

```php
final class ApprovalWorkflowProvider implements WorkflowDefinitionProviderInterface
{
    public function getOwnerKey(): string { return 'vendor/approval'; }
    public function getSourceVersion(): string { return '1.2.0'; }

    public function getDefinitions(): iterable
    {
        yield new WorkflowDefinitionData(
            'review_request',
            'Review request',
            $this->graphDocument(),
            'vendor.approval.policy',
        );
    }
}
```

Do not encode credentials, callback URLs, PHP class names, or arbitrary executable expressions in the graph. Plugins own their source definition; admins can assign it or fork it, not rewrite the plugin-owned definition in place.

## REST API

All routes are under `/api/admin/workflows` and require per-operation policy/site checks:

- `GET /sites`, `GET /catalog`
- `GET|POST /definitions`, `GET|PUT /definitions/{id}`
- `POST /definitions/{id}/assignments/{siteId}`, `POST /definitions/{id}/fork`, `POST /definitions/{id}/archive`
- `POST /instances`, `GET /instances/{id}`, `POST /instances/{id}/transitions/{name}`
- `GET /tasks`, `GET /tasks/sites`, `POST /tasks/{id}/claim`, `POST /tasks/{id}/release`

Transition requests accept form `data` only for a place schema; Symfony Form validation rejects missing required values and extra fields. Transition IDs are checked against enabled transitions and policy guards. The API returns 409 for stale optimistic-lock updates and 202 when an unavailable plugin pauses an instance.

## MFA policy boundary

Authorized site/global admins can edit the assigned `mfa_authentication` graph; plugins may contribute registered factor providers or graph definitions. The policy's factor methods are resolved from the existing application/site/user MFA preferences. `MfaGraphProofValidator` explores branch/fork/join markings for the current user's enabled factors and rejects any terminal path that can omit a required proof; at each final guard and again in `MfaChallengeController`, the code-enforced proof gate checks the proof keys. A graph cannot set a proof or authenticate directly.

Session challenges carry the graph version and effective policy fingerprint. A changed graph, factor policy, e-mail/TOTP material, or factor-provider version invalidates the old challenge and starts under the current version. Backup-code consumption is tracked separately from the other fingerprint inputs so one successfully consumed code does not invalidate its own challenge; changes to the remaining code set still restart it.

## Digital asset cutover

Existing `AssetWorkflowDefinition` rows become core workflow definition versions with `metadata.assetProcessor` (`processorKey`, MIME pattern, conditions, options, `allSites`). Existing `AssetWorkflowRun` rows become generic `WorkflowInstance` records with `subjectType: digital_asset`, the same status marking, attempts, error, and an idempotency key. The overall asset `processingStatus` remains the code-owned `asset_processing` attribute machine.

The asset-specific `/api/admin/asset-workflows` route and `workflowRuns` asset-detail response are intentionally removed; use the generic workflow API and `workflowInstances`. The old `AssetWorkflowDefinition` FQCN remains only as a non-persisted value object passed to `DigitalAssetProcessorInterface`, preserving existing plugin processor implementations; no `AssetWorkflowDefinition` or `AssetWorkflowRun` Doctrine entity/table remains after migration `Version20261015003000`.

## Deployment sequence

The asset table cleanup is the contract phase and must not run before the backfill is verified:

1. Back up the database and pause/drain `asset_processing` Messenger workers.
2. Apply through `Version20261015002000` (existing asset schema, generic workflow tables, asset definition/run backfill): `php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261015002000'` (single backslash — the resolver takes the FQCN as written by the shell; an interactive TTY also shows an "executed but unavailable" confirm for four pre-EAV migrations that must be answered yes).
3. Deploy the code/API/UI cutover, build the admin SPA (`bun run build` — `public/build` is not committed), run `composer lint:container`, warm the Symfony cache, and restart Messenger workers.
4. Verify legacy-vs-generic definition/run counts, migrated markings/attempt/error values, asset detail `workflowInstances`, one successful processor run, and a failed/retry path.
5. Only after verification, apply `Version20261015003000` to drop the old asset workflow tables, then apply `Version20261015004000` and `Version20261015005000` to align optimistic-lock defaults and the schema-qualified site-assignment indexes.

Never use `doctrine:schema:update --force` for this feature; migrations own the schema transition.
