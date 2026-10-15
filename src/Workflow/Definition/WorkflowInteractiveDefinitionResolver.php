<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\WorkflowGraphFactoryInterface;

final class WorkflowInteractiveDefinitionResolver
{
	public const SESSION_GRAPH_KEY = WorkflowGraphFactoryInterface::CONTEXT_GRAPH_KEY;
	public const SESSION_DEFINITION_ID = '_workflow_definition_id';
	public const SESSION_VERSION_ID = '_workflow_definition_version_id';
	public const SESSION_USER_ID = '_workflow_user_id';
	public const SESSION_SITE_ID = '_workflow_site_id';
	public const SESSION_ROUTE_KEY = '_workflow_route_key';

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly WorkflowGraphCompiler $graphCompiler,
	) {
	}

	public function resolveCurrent(string $key, Site $site): ?WorkflowDefinitionVersion
	{
		$definitions = $this->entityManager->getRepository(WorkflowDefinition::class)->findAssignedToSite($site, true);
		$definitions = array_values(array_filter($definitions, static fn (WorkflowDefinition $definition): bool => $definition->getKey() === $key));
		$definitions = array_values(array_filter($definitions, fn (WorkflowDefinition $definition): bool => $this->ownerIsAvailable($definition, $site) &&
			null !== ($version = $this->versionFor($definition)) &&
			$this->isInteractiveVersion($version)
		));
		usort($definitions, static fn (WorkflowDefinition $left, WorkflowDefinition $right): int => self::priority($left) <=> self::priority($right));
		if ([] === $definitions) {
			return null;
		}
		if (isset($definitions[1]) && self::priority($definitions[0]) === self::priority($definitions[1])) {
			throw new \LogicException(sprintf('Multiple active interactive workflow definitions named "%s" are assigned to this site.', $key));
		}

		return $this->versionFor($definitions[0]);
	}

	public function resolvePinned(int|string $versionId, string $key, Site $site): ?WorkflowDefinitionVersion
	{
		$version = $this->entityManager->find(WorkflowDefinitionVersion::class, $versionId);
		if (!$version instanceof WorkflowDefinitionVersion) {
			return null;
		}
		$definition = $version->getDefinition();
		if ($definition->getKey() !== $key || !$definition->hasSite($site) || !$this->ownerIsAvailable($definition, $site) || !$this->isInteractiveVersion($version)) {
			return null;
		}

		return $version;
	}

	/** @return array<string,mixed> */
	public function createSessionContext(WorkflowDefinitionVersion $version, User $user, Site $site, string $routeKey): array
	{
		return [
			self::SESSION_GRAPH_KEY => $version->getGraph(),
			self::SESSION_DEFINITION_ID => $version->getDefinition()->getId(),
			self::SESSION_VERSION_ID => $version->getId(),
			self::SESSION_USER_ID => $user->getId(),
			self::SESSION_SITE_ID => $site->getId(),
			self::SESSION_ROUTE_KEY => $routeKey,
		];
	}

	public function runtimeName(string $key): string
	{
		return 'db__'.$key;
	}

	private function isInteractiveVersion(WorkflowDefinitionVersion $version): bool
	{
		$graph = $this->compileGraph($version);
		if ('session' !== ($graph->workflowMetadata['mode'] ?? null)) {
			return false;
		}
		foreach ($graph->placesMetadata as $metadata) {
			if (is_array($metadata['form'] ?? null)) {
				return true;
			}
		}

		return false;
	}

	private function ownerIsAvailable(WorkflowDefinition $definition, Site $site): bool
	{
		return 'plugin' !== $definition->getOwnerType() ||
			$site->getConfiguration()->isPluginActive($definition->getOwnerKey());
	}

	private function versionFor(WorkflowDefinition $definition): ?WorkflowDefinitionVersion
	{
		$version = $this->entityManager->getRepository(WorkflowDefinitionVersion::class)->findOneBy([
			'definition' => $definition,
			'version' => $definition->getCurrentVersion(),
		]);

		return $version instanceof WorkflowDefinitionVersion ? $version : null;
	}

	private function compileGraph(WorkflowDefinitionVersion $version): WorkflowGraph
	{
		$graph = $this->graphCompiler->compile($version->getGraph());
		if (isset($graph->workflowMetadata['assetProcessor'])) {
			throw new \InvalidArgumentException('Asset processor workflows cannot be started as interactive forms.');
		}
		foreach ($graph->placesMetadata as $metadata) {
			if (isset($metadata['humanTask']) || isset($metadata['mfaFactorKey'])) {
				throw new \InvalidArgumentException('Durable human tasks and MFA factors cannot be used as generic session form places.');
			}
		}
		foreach ($graph->transitions as $transition) {
			if (isset($transition->metadata['actionKey'])) {
				throw new \InvalidArgumentException('Session form workflows cannot invoke durable transition actions.');
			}
		}

		return $graph;
	}

	private static function priority(WorkflowDefinition $definition): int
	{
		return match ($definition->getOwnerType()) {
			'site' => 0,
			'plugin' => 1,
			default => 2,
		};
	}
}
