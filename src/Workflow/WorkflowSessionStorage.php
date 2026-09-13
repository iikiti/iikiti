<?php

namespace iikiti\CMS\Workflow;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Persists workflow progress between requests.
 *
 * Only the serializable state of a workflow is stored. Steps are rebuilt on
 * every request by a {@see WorkflowFactoryInterface}, because step instances
 * may hold services that cannot be serialized.
 */
class WorkflowSessionStorage
{
	private const SESSION_KEY = '_workflows';

	public function __construct(private readonly RequestStack $requestStack)
	{
	}

	/**
	 * @param array<string,mixed> $state
	 */
	public function save(string $key, array $state): void
	{
		$session = $this->getSession();
		$workflows = $this->readWorkflows($session);
		$workflows[$key] = $state;
		$session->set(self::SESSION_KEY, $workflows);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function load(string $key): ?array
	{
		$state = $this->readWorkflows($this->getSession())[$key] ?? null;

		return is_array($state) ? $state : null;
	}

	public function has(string $key): bool
	{
		return null !== $this->load($key);
	}

	public function remove(string $key): void
	{
		$session = $this->getSession();
		$workflows = $this->readWorkflows($session);
		unset($workflows[$key]);
		$session->set(self::SESSION_KEY, $workflows);
	}

	/**
	 * Extract the persistable state from a workflow.
	 *
	 * @return array<string,mixed>
	 */
	public static function extractState(WorkflowInterface $workflow): array
	{
		return [
			'name' => $workflow->getName(),
			'context' => $workflow->getContext(),
			'current_step' => $workflow->getCurrentStep()?->getId(),
			'complete' => $workflow->isComplete(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function readWorkflows(SessionInterface $session): array
	{
		$workflows = $session->get(self::SESSION_KEY, []);

		return is_array($workflows) ? $workflows : [];
	}

	private function getSession(): SessionInterface
	{
		return $this->requestStack->getSession();
	}
}
