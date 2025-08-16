<?php

namespace iikiti\CMS\Workflow\Example;

use iikiti\CMS\Workflow\WorkflowManager;
use iikiti\CMS\Workflow\Step\AbstractWorkflowStep;

class MultiPageFormWorkflow
{
    private WorkflowManager $workflowManager;

    public function __construct(WorkflowManager $workflowManager)
    {
        $this->workflowManager = $workflowManager;
    }

    public function createRegistrationForm(array $formData = []): \iikiti\CMS\Workflow\WorkflowInterface
    {
        $workflow = $this->workflowManager->createWorkflow('registration_form', [
            'form_type' => 'registration',
            'initial_data' => $formData,
        ]);

        // Add form steps
        $workflow->addStep(new PersonalInfoStep());
        $workflow->addStep(new ContactInfoStep());
        $workflow->addStep(new PreferencesStep());
        $workflow->addStep(new ReviewStep());
        $workflow->addStep(new ConfirmationStep());

        return $workflow;
    }
}

class PersonalInfoStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('personal_info', 'Personal Information', true, false);
    }

    public function validate(array $context): bool
    {
        return isset($context['first_name']) && 
               isset($context['last_name']) && 
               isset($context['date_of_birth']);
    }

    public function process(array $data, array $context): array
    {
        return [
            'personal_info' => [
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'date_of_birth' => $data['date_of_birth'] ?? '',
            ]
        ];
    }
}

class ContactInfoStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('contact_info', 'Contact Information', true, false);
    }

    public function validate(array $context): bool
    {
        return isset($context['email']) && 
               isset($context['phone']) && 
               isset($context['address']);
    }

    public function process(array $data, array $context): array
    {
        return [
            'contact_info' => [
                'email' => $data['email'] ?? '',
                'phone' => $data['phone'] ?? '',
                'address' => $data['address'] ?? '',
            ]
        ];
    }
}

class PreferencesStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('preferences', 'Preferences', false, true);
    }

    public function validate(array $context): bool
    {
        return true; // Optional step
    }

    public function process(array $data, array $context): array
    {
        return [
            'preferences' => [
                'newsletter' => $data['newsletter'] ?? false,
                'notifications' => $data['notifications'] ?? false,
                'theme' => $data['theme'] ?? 'default',
            ]
        ];
    }
}

class ReviewStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('review', 'Review & Confirm', true, false);
    }

    public function validate(array $context): bool
    {
        return isset($context['confirmed']) && $context['confirmed'] === true;
    }

    public function process(array $data, array $context): array
    {
        return [
            'confirmed' => true,
            'timestamp' => time(),
        ];
    }
}

class ConfirmationStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('confirmation', 'Confirmation', true, false);
    }

    public function validate(array $context): bool
    {
        return true; // Final step
    }

    public function process(array $data, array $context): array
    {
        return [
            'registration_complete' => true,
            'registration_id' => uniqid('reg_'),
            'completed_at' => time(),
        ];
    }
}