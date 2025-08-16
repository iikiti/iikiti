<?php

namespace iikiti\CMS\Workflow\Step;

use iikiti\CMS\Workflow\WorkflowStepInterface;

abstract class AbstractWorkflowStep implements WorkflowStepInterface
{
    private string $id;
    private string $name;
    private bool $required;
    private bool $skippable;
    private array $configuration;

    public function __construct(
        string $id,
        string $name,
        bool $required = true,
        bool $skippable = false,
        array $configuration = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->required = $required;
        $this->skippable = $skippable;
        $this->configuration = $configuration;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function canSkip(): bool
    {
        return $this->skippable;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function isAvailable(array $context): bool
    {
        return true;
    }

    abstract public function validate(array $context): bool;
    abstract public function process(array $data, array $context): array;
}