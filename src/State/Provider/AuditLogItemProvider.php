<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\AuditLogResource;
use iikiti\CMS\Repository\AuditLogEntryRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Returns one audit log entry with its sub-events for the detail view.
 *
 * @implements ProviderInterface<AuditLogResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class AuditLogItemProvider implements ProviderInterface
{
	public function __construct(
		private AuditLogEntryRepository $auditLogRepository,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?object
	{
		$id = (int) ($uriVariables['id'] ?? 0);
		if ($id <= 0) {
			return null;
		}

		$entry = $this->auditLogRepository->find($id);

		return null === $entry ? null : AuditLogResource::fromEntity($entry, true);
	}
}
