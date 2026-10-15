<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Step\Mfa;

use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\WorkflowTransitionCommittedStepInterface;

final class MfaFactorStepAdapter implements FormStepInterface, WorkflowTransitionCommittedStepInterface
{
	public function __construct(private readonly string $id, private readonly FormStepInterface $step)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->step->getName();
	}

	public function isRequired(): bool
	{
		return $this->step->isRequired();
	}

	public function canSkip(): bool
	{
		return $this->step->canSkip();
	}

	public function validate(array $context): bool
	{
		return $this->step->validate($context);
	}

	public function process(array $data, array $context): array
	{
		return $this->step->process($data, $context);
	}

	public function getConfiguration(): array
	{
		return $this->step->getConfiguration();
	}

	public function isAvailable(array $context): bool
	{
		return $this->step->isAvailable($context);
	}

	public function getFormType(): string
	{
		return $this->step->getFormType();
	}

	public function getFormOptions(array $context): array
	{
		return $this->step->getFormOptions($context);
	}

	public function prepare(array $context): array
	{
		return $this->step->prepare($context);
	}

	public function onTransitionCommitted(): void
	{
		if ($this->step instanceof WorkflowTransitionCommittedStepInterface) {
			$this->step->onTransitionCommitted();
		}
	}
}
