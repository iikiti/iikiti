<?php

namespace iikiti\CMS\Workflow\Example;

use iikiti\CMS\Workflow\WorkflowManager;
use iikiti\CMS\Workflow\Step\AbstractWorkflowStep;

class MfaWorkflow
{
    private WorkflowManager $workflowManager;

    public function __construct(WorkflowManager $workflowManager)
    {
        $this->workflowManager = $workflowManager;
    }

    /** @param array<string,mixed> $userData */
    public function createMfaWorkflow(array $userData = []): \iikiti\CMS\Workflow\WorkflowInterface
    {
        $workflow = $this->workflowManager->createWorkflow('mfa_authentication', [
            'user_id' => $userData['user_id'] ?? null,
            'username' => $userData['username'] ?? null,
            'email' => $userData['email'] ?? null,
            'phone' => $userData['phone'] ?? null,
        ]);

        // Add MFA steps
        $workflow->addStep(new UsernamePasswordStep());
        $workflow->addStep(new EmailVerificationStep());
        $workflow->addStep(new SmsVerificationStep());
        $workflow->addStep(new BackupCodeStep());

        return $workflow;
    }
}

class UsernamePasswordStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('username_password', 'Username & Password', true, false);
    }

    /** @param array<string,mixed> $context */
    public function validate(array $context): bool
    {
        return isset($context['username']) && isset($context['password']);
    }

    /** @param array<string,mixed> $data */
    /** @param array<string,mixed> $context */
    /** @return array<string,mixed> */
    public function process(array $data, array $context): array
    {
        // Validate credentials against database
        return [
            'authenticated' => true,
            'user_id' => $context['user_id'] ?? null,
        ];
    }
}

class EmailVerificationStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('email_verification', 'Email Verification', true, true);
    }

    /** @param array<string,mixed> $context */
    public function validate(array $context): bool
    {
        return isset($context['email_verification_code']);
    }

    /** @param array<string,mixed> $data */
    /** @param array<string,mixed> $context */
    /** @return array<string,mixed> */
    public function process(array $data, array $context): array
    {
        // Validate email verification code
        return [
            'email_verified' => true,
            'verification_method' => 'email',
        ];
    }

    /** @param array<string,mixed> $context */
    public function isAvailable(array $context): bool
    {
        return isset($context['email']) && !empty($context['email']);
    }
}

class SmsVerificationStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('sms_verification', 'SMS Verification', false, true);
    }

    /** @param array<string,mixed> $context */
    public function validate(array $context): bool
    {
        return isset($context['sms_verification_code']);
    }

    /** @param array<string,mixed> $data */
    /** @param array<string,mixed> $context */
    /** @return array<string,mixed> */
    public function process(array $data, array $context): array
    {
        // Validate SMS verification code
        return [
            'sms_verified' => true,
            'verification_method' => 'sms',
        ];
    }

    /** @param array<string,mixed> $context */
    public function isAvailable(array $context): bool
    {
        return isset($context['phone']) && !empty($context['phone']);
    }
}

class BackupCodeStep extends AbstractWorkflowStep
{
    public function __construct()
    {
        parent::__construct('backup_code', 'Backup Code', false, true);
    }

    /** @param array<string,mixed> $context */
    public function validate(array $context): bool
    {
        return isset($context['backup_code']);
    }

    /** @param array<string,mixed> $data */
    /** @param array<string,mixed> $context */
    /** @return array<string,mixed> */
    public function process(array $data, array $context): array
    {
        // Validate backup code
        return [
            'backup_code_used' => true,
            'verification_method' => 'backup_code',
        ];
    }
}