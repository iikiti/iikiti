<?php

namespace iikiti\CMS\Workflow;

interface WorkflowStepInterface
{
    /**
     * Get the unique identifier for this step
     */
    public function getId(): string;

    /**
     * Get the display name for this step
     */
    public function getName(): string;

    /**
     * Check if this step is required
     */
    public function isRequired(): bool;

    /**
     * Check if this step can be skipped
     */
    public function canSkip(): bool;

    /**
     * Validate the step data
     *
     * @param array<string,mixed> $context
     */
    public function validate(array $context): bool;

    /**
     * Process the step data
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    public function process(array $data, array $context): array;

    /**
     * Get the step configuration
     *
     * @return array<string,mixed>
     */
    public function getConfiguration(): array;

    /**
     * Check if step is available based on context
     *
     * @param array<string,mixed> $context
     */
    public function isAvailable(array $context): bool;
}