<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Authentication\MfaPreferenceResolver;
use iikiti\CMS\Authentication\MfaUserConfiguration;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;

final class MfaWorkflowDefinitionResolver
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly MfaPreferenceResolver $preferences,
		private readonly MfaFactorProviderRegistry $factors,
		private readonly MfaProofPolicy $proofPolicy,
		private readonly MfaGraphProofValidator $proofValidator,
		private readonly WorkflowGraphCompiler $graphCompiler,
	) {
	}

	/** @param array<string,mixed> $sessionContext */
	public function resolve(User $user, array $sessionContext = []): MfaWorkflowSnapshot
	{
		$configuration = $this->preferences->resolve($user);
		$methods = $configuration->getEnabledMethods();
		if ([] === $methods) {
			throw new \LogicException('MFA is required but no factor methods are configured.');
		}
		foreach ($methods as $method) {
			$provider = $this->factors->get($method);
			if (null === $provider) {
				throw new \LogicException(sprintf('Required MFA factor "%s" is not registered.', $method));
			}
			if (!$provider->isAvailableFor($user)) {
				throw new \LogicException(sprintf('Required MFA factor "%s" is unavailable for this user.', $method));
			}
		}
		if (in_array(MfaUserConfiguration::METHOD_TOTP, $methods, true) && null === $configuration->getTotpSecret() && true !== ($sessionContext['totp_verified'] ?? false)) {
			throw new \LogicException('TOTP is required by the MFA policy but no authenticator secret is configured.');
		}
		if (in_array(MfaUserConfiguration::METHOD_BACKUP_CODE, $methods, true) && [] === $configuration->getBackupCodeHashes() && true !== ($sessionContext['backup_code_used'] ?? false)) {
			throw new \LogicException('Backup codes are required by the MFA policy but none remain.');
		}
		$requiredProofs = $this->proofPolicy->requiredProofKeys($methods);
		$definitionVersion = $this->findAssignedDefinition($user);
		$graph = null;
		$versionKey = MfaStepProvider::DEFAULT_GRAPH_VERSION;
		if (null !== $definitionVersion) {
			$graphObject = $this->graphCompiler->compile($definitionVersion->getGraph());
			if (($graphObject->workflowMetadata['policyKey'] ?? null) !== MfaProofWorkflowPolicy::KEY) {
				throw new \LogicException('MFA workflow graph must use the non-bypassable core proof policy.');
			}
			$graphFactors = [];
			foreach ($graphObject->placesMetadata as $metadata) {
				$factorKey = $metadata['mfaFactorKey'] ?? null;
				if (is_string($factorKey)) {
					if (null === $this->factors->get($factorKey)) {
						throw new \LogicException(sprintf('MFA workflow references unregistered factor "%s".', $factorKey));
					}
					$graphFactors[] = $factorKey;
				}
			}
			foreach ($methods as $method) {
				if (!in_array($method, $graphFactors, true)) {
					throw new \LogicException(sprintf('MFA workflow graph does not include required factor "%s".', $method));
				}
			}
			$this->proofValidator->validate($graphObject, $requiredProofs, $methods);
			$graph = $graphObject->toDocument();
			$versionKey = (string) ($definitionVersion->getId() ?? sprintf('%s:%d', $definitionVersion->getDefinition()->getKey(), $definitionVersion->getVersion()));
		}

		$email = $user->getEmails()[0] ?? null;
		$policyMaterial = [
			'graphVersion' => $versionKey,
			'methods' => $methods,
			'factorVersions' => $this->factors->versionsFor($methods),
			'email' => is_string($email) ? $email : null,
			'totpSecret' => $configuration->getTotpSecret(),
		];
		$fingerprint = hash('sha256', json_encode($policyMaterial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

		return new MfaWorkflowSnapshot($versionKey, $fingerprint, $methods, $requiredProofs, $graph, $configuration, is_string($email) ? $email : null);
	}

	/** @return array<string,mixed> */
	public function createSessionContext(User $user, MfaWorkflowSnapshot $snapshot): array
	{
		return [
			'user_id' => $user->getId(),
			MfaStepProvider::CONTEXT_METHODS => $snapshot->methods,
			MfaStepProvider::CONTEXT_EMAIL => $snapshot->email,
			MfaStepProvider::CONTEXT_TOTP_SECRET => $snapshot->configuration->getTotpSecret(),
			MfaStepProvider::CONTEXT_BACKUP_CODES => $snapshot->configuration->getBackupCodeHashes(),
			'mfa_backup_code_hashes_used' => [],
			MfaStepProvider::CONTEXT_GRAPH => $snapshot->graph,
			MfaStepProvider::CONTEXT_DEFINITION_VERSION => $snapshot->versionKey,
			MfaStepProvider::CONTEXT_POLICY_FINGERPRINT => $snapshot->policyFingerprint,
			MfaStepProvider::CONTEXT_REQUIRED_PROOFS => $snapshot->requiredProofs,
		];
	}

	private function findAssignedDefinition(User $user): ?WorkflowDefinitionVersion
	{
		$site = SiteRegistry::getCurrent();
		$definitions = $this->entityManager->getRepository(WorkflowDefinition::class)->findAssignedToSite($site, true);
		$definitions = array_values(array_filter($definitions, static fn (WorkflowDefinition $definition): bool => MfaStepProvider::WORKFLOW_NAME === $definition->getKey()));
		$choices = array_values(array_filter($definitions, static fn (WorkflowDefinition $definition): bool => 'site' === $definition->getOwnerType() && $definition->getOwnerKey() === (string) $site->getId() ||
			'core' === $definition->getOwnerType() ||
			('plugin' === $definition->getOwnerType() && $site->getConfiguration()->isPluginActive($definition->getOwnerKey()))
		));
		usort($choices, static fn (WorkflowDefinition $left, WorkflowDefinition $right): int => match (true) {
			$left->getOwnerType() === $right->getOwnerType() => 0,
			'site' === $left->getOwnerType() => -1,
			'site' === $right->getOwnerType() => 1,
			'plugin' === $left->getOwnerType() => -1,
			'plugin' === $right->getOwnerType() => 1,
			default => 0,
		}
		);
		if (count($choices) > 1 && $choices[0]->getOwnerType() === $choices[1]->getOwnerType()) {
			throw new \LogicException('Multiple active MFA workflow definitions have equal precedence for this site.');
		}
		$definition = $choices[0] ?? null;
		if (!$definition instanceof WorkflowDefinition) {
			return null;
		}

		$version = $this->entityManager->getRepository(WorkflowDefinitionVersion::class)->findOneBy([
			'definition' => $definition,
			'version' => $definition->getCurrentVersion(),
		]);

		return $version instanceof WorkflowDefinitionVersion ? $version : throw new \LogicException('MFA workflow definition has no current version.');
	}
}
