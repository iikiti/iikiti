# Workflow examples

This guide shows how to build multi-step workflows with the abstract workflow
engine in `src/Workflow/`. Each example is based on the actual classes in the
codebase, so the code can be copied and adapted.

For the MFA implementation built on this engine, see [mfa-workflow.md](mfa-workflow.md).

## Concepts

| Class / interface                        | Role                                                                 |
| ---------------------------------------- | -------------------------------------------------------------------- |
| `WorkflowManager`                        | Creates workflows and holds step providers per workflow name.        |
| `Workflow` (`WorkflowInterface`)         | Ordered list of steps, shared context, and navigation.               |
| `WorkflowStepInterface`                  | A single step with `validate()` and `process()`.                     |
| `AbstractWorkflowStep`                   | Base class for custom steps (id, name, required, skippable, config). |
| `AbstractFormWorkflowStep`               | Base class for steps that render a Symfony `FormType`.               |
| `StepProviderInterface`                  | Builds steps for a workflow from its context at runtime.             |
| `WorkflowEvents`                         | Events fired on init, navigation, validation, and completion.        |

Workflow lifecycle:

1. A workflow is created with an initial context.
2. Steps are added (directly or by a step provider).
3. `submitCurrentStep($data)` validates the current step, merges its output into
   the context, and advances. Rejected data never reaches the persisted context.
4. When the last step succeeds, the workflow is marked complete and
   `WORKFLOW_COMPLETED` is dispatched.

## Example 1: A minimal workflow with custom steps

Use `WorkflowManager` to create a workflow and add steps directly. This is the
simplest approach when the step list is fixed.

```php
<?php

namespace App\Onboarding;

use iikiti\CMS\Workflow\Step\AbstractWorkflowStep;
use iikiti\CMS\Workflow\WorkflowManager;

final class OnboardingWorkflowFactory
{
    public function __construct(private readonly WorkflowManager $workflowManager)
    {
    }

    public function create(): \iikiti\CMS\Workflow\WorkflowInterface
    {
        $workflow = $this->workflowManager->createWorkflow('onboarding', [
            'source' => 'signup_page',
        ]);

        $workflow->addStep(new CompanyNameStep());
        $workflow->addStep(new TeamSizeStep());

        return $workflow;
    }
}

final class CompanyNameStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('company_name', 'Company name', required: true);
    }

    public function validate(array $context): bool
    {
        return isset($context['company_name']) && '' !== trim((string) $context['company_name']);
    }

    public function process(array $data, array $context): array
    {
        return ['company_name' => trim((string) ($data['company_name'] ?? ''))];
    }
}

final class TeamSizeStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('team_size', 'Team size', required: false, skippable: true);
    }

    public function validate(array $context): bool
    {
        // Optional step: any value (including none) is acceptable.
        return true;
    }

    public function process(array $data, array $context): array
    {
        return ['team_size' => max(1, (int) ($data['team_size'] ?? 1))];
    }
}
```

Driving it:

```php
$workflow = $onboardingFactory->create();

// Step 1: rejected, nothing is merged and the index does not move.
$workflow->submitCurrentStep(['company_name' => '']);   // returns false

// Step 1: accepted, context gains company_name and the workflow advances.
$workflow->submitCurrentStep(['company_name' => 'Acme Ltd']); // returns true

// Step 2 (optional) and completion.
$workflow->submitCurrentStep(['team_size' => 12]);

$workflow->isComplete();   // true
$workflow->getContext();   // ['source' => 'signup_page', 'company_name' => 'Acme Ltd', 'team_size' => 12]
```

Note that `validate()` receives the context merged with the submitted data, so a
step can check values it has just been given.

## Example 2: A form-based workflow

When each step is a Symfony form, extend `AbstractFormWorkflowStep`. The step
only needs to name its form type; add `validate()` or `process()` when
server-side checks or transformations are required.

```php
<?php

namespace App\Registration;

use App\Form\Type\AddressFormType;
use App\Form\Type\PersonalDetailsFormType;
use iikiti\CMS\Workflow\Step\AbstractFormWorkflowStep;

final class PersonalDetailsStep extends AbstractFormWorkflowStep
{
    public function __construct()
    {
        parent::__construct(
            id: 'personal',
            name: 'Personal details',
            formType: PersonalDetailsFormType::class,
        );
    }
}

final class AddressStep extends AbstractFormWorkflowStep
{
    public function __construct()
    {
        parent::__construct(
            id: 'address',
            name: 'Address',
            formType: AddressFormType::class,
        );
    }

    /** Only allow addresses in the supported country list. */
    public function validate(array $context): bool
    {
        return in_array($context['country'] ?? null, ['GB', 'IE', 'NL'], true);
    }
}
```

Use `getFormOptions()` to pass per-request options (for example, a list of
allowed roles) and `prepare()` to compute data before the form is rendered.

## Example 3: Dynamic workflows from a step provider

When steps depend on runtime data, implement `StepProviderInterface` and tag it
`workflow.step_provider` (the interface carries the `AutoconfigureTag` attribute,
so implementing classes are tagged automatically). The provider returns steps
from the workflow context.

```php
<?php

namespace App\Workflow;

use iikiti\CMS\Workflow\StepProviderInterface;
use iikiti\CMS\Workflow\WorkflowInterface;
use iikiti\CMS\Workflow\WorkflowStepInterface;

final class PurchaseStepProvider implements StepProviderInterface
{
    public const WORKFLOW_NAME = 'purchase';

    public function getWorkflowName(): string
    {
        return self::WORKFLOW_NAME;
    }

    public function supports(mixed $context): bool
    {
        return is_array($context) && isset($context['items']);
    }

    /** @return array<WorkflowStepInterface> */
    public function provideSteps(WorkflowInterface $workflow, mixed $context): array
    {
        $steps = [new CartReviewStep()];

        // Shipping is only needed for physical goods.
        if (!empty($context['requires_shipping'])) {
            $steps[] = new ShippingStep();
        }

        $steps[] = new PaymentStep();

        return $steps;
    }
}
```

Because the step list depends on `requires_shipping`, a digital-only order skips
the shipping step entirely. The MFA workflow works the same way: `MfaStepProvider`
produces one step per enabled method.

### Using the bundled dynamic form provider

The codebase already ships a provider that builds steps from a JSON-style
definition in the `steps` context key (`DynamicFormStepProvider`, workflow name
`dynamic_form`). A definition looks like this:

```json
{
  "steps": [
    {
      "id": "personal",
      "name": "Personal details",
      "fields": [
        { "name": "full_name", "type": "Symfony\\Component\\Form\\Extension\\Core\\Type\\TextType" },
        { "name": "email", "type": "Symfony\\Component\\Form\\Extension\\Core\\Type\\EmailType" }
      ]
    }
  ]
}
```

It is limited to 20 steps and 50 fields per step. It is served by the
`/forms/{workflow}` route and can be started by passing the JSON definition in
the `steps` query parameter.

## Example 4: Building steps with `WorkflowManager::buildWorkflow()`

If you register providers with `addStepProvider()`, `buildWorkflow()` assembles
the workflow in one call. Each provider is a callable that receives the workflow
and its context and returns an array of steps.

```php
$workflowManager->addStepProvider('newsletter', function ($workflow, array $context): array {
    $steps = [new TopicsStep()];

    if (($context['frequency'] ?? null) === 'weekly') {
        $steps[] = new DayOfWeekStep();
    }

    return $steps;
});

$workflow = $workflowManager->buildWorkflow('newsletter', ['frequency' => 'weekly']);
```

Only objects implementing `WorkflowStepInterface` are added; other return values
are ignored.

## Example 5: Reacting to workflow events

Listen to `WorkflowEvents` to add side effects such as auditing, logging, or
emitting notifications, without changing the step classes.

```php
<?php

namespace App\Workflow;

use iikiti\CMS\Workflow\WorkflowEvents;
use iikiti\CMS\Workflow\WorkflowStepEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OnboardingAuditSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WorkflowEvents::STEP_VALIDATION_FAILED => 'onValidationFailed',
            WorkflowEvents::WORKFLOW_COMPLETED => 'onCompleted',
        ];
    }

    public function onValidationFailed(WorkflowStepEvent $event): void
    {
        // Track which steps users struggle with. Do not log submitted values.
        $this->logger->info('Onboarding step rejected', [
            'step' => $event->getStep()?->getId(),
        ]);
    }

    public function onCompleted(WorkflowEvent $event): void
    {
        $this->logger->info('Onboarding completed', [
            'workflow' => $event->getWorkflow()->getName(),
        ]);
    }
}
```

| Event                      | Fired when                                    | Event class           |
| -------------------------- | --------------------------------------------- | --------------------- |
| `WORKFLOW_INITIALIZED`     | A workflow is created                         | `WorkflowEvent`       |
| `STEP_ADDED`               | A step is added                               | `WorkflowStepEvent`   |
| `STEP_VALIDATED`           | The current step passes validation            | `WorkflowStepEvent`   |
| `STEP_VALIDATION_FAILED`   | The current step fails validation             | `WorkflowStepEvent`   |
| `STEP_NEXT`                | The workflow advances to the next step        | `WorkflowStepEvent`   |
| `STEP_PREVIOUS`            | The workflow returns to the previous step     | `WorkflowStepEvent`   |
| `STEP_JUMP`                | The workflow jumps to a step by id            | `WorkflowStepEvent`   |
| `WORKFLOW_COMPLETED`       | The last step succeeds                        | `WorkflowEvent`       |

Validation events carry `['valid' => bool]` as event data.

## Example 6: Persisting state between requests

Workflows are stateful. To survive across HTTP requests, store the context and
rebuild the workflow on the next request. `WorkflowSessionStorage` and
`WorkflowFactory` do this for the session.

```php
// Request 1: start and advance.
$workflow = $workflowFactory->create('onboarding', $initialContext);
$workflow->submitCurrentStep($request->request->all());
$storage->save($workflow);

// Request 2: restore and continue.
$workflow = $storage->load('onboarding');
$currentStep = $workflow->getCurrentStep();
```

Keep only data that is safe to persist in the session. Sensitive values such as
one-time codes should be stored as hashes, as the MFA e-mail step does.

## Good practice

- **Validate on the server.** `validate()` is the authority. Do not rely on
  client-side checks, and sanitise input in `process()` before it is stored.
- **Keep steps single-purpose.** One step should own one piece of business logic.
  Put shared checks in a service and call it from `validate()`.
- **Use `required` and `skippable` deliberately.** These flags describe the
  step; they do not enforce the flow on their own. `validate()` decides whether
  a step passes.
- **Keep `process()` pure.** It should return only the data to merge and not
  perform side effects. Put side effects (sending mail, writing audit records)
  in an event subscriber or a service.
- **Do not store secrets in plain text in the context.** Hash one-time codes and
  never log submitted values.

## Testing a workflow

Test steps in isolation, then test the full flow once.

```php
public function testRejectsBlankCompanyName(): void
{
    $step = new CompanyNameStep();

    self::assertFalse($step->validate(['company_name' => '   ']));
    self::assertTrue($step->validate(['company_name' => 'Acme Ltd']));
}

public function testWorkflowCompletesAfterAllSteps(): void
{
    $workflow = $this->factory->create();

    self::assertTrue($workflow->submitCurrentStep(['company_name' => 'Acme Ltd']));
    self::assertTrue($workflow->submitCurrentStep(['team_size' => 5]));
    self::assertTrue($workflow->isComplete());
}
```

Run the suite with:

```
vendor/bin/phpunit --no-coverage
```
