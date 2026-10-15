<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

final readonly class WorkflowDefinitionData
{
	/** @param array<string,mixed> $graph */
	public function __construct(
		public string $key,
		public string $name,
		public array $graph,
		public string $policyKey,
	) {
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key)) {
			throw new \InvalidArgumentException('Workflow definition key must be a lowercase identifier.');
		}
		if ('' === trim($name) || mb_strlen($name) > 128) {
			throw new \InvalidArgumentException('Workflow definition name must contain 1–128 characters.');
		}
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $policyKey)) {
			throw new \InvalidArgumentException('Plugin workflow definitions must declare a stable policy key.');
		}
		if ([] === $graph) {
			throw new \InvalidArgumentException('Workflow definition graph cannot be empty.');
		}
	}
}
