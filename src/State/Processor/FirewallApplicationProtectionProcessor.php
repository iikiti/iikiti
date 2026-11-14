<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\FirewallApplicationProtectionResource;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Security\Firewall\Admin\FirewallApplicationLookup;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProcessorInterface<FirewallApplicationProtectionResource,FirewallApplicationProtectionResource> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallApplicationProtectionProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallApplicationLookup $applications,
		private FirewallProtectionPolicyStore $policies,
		private EntityManagerInterface $entityManager,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallApplicationProtectionResource
	{
		$application = $this->applications->require($uriVariables);
		$before = $this->policies->getApplicationPolicy($application)['version'];

		try {
			$version = $this->policies->setApplicationPolicy($application, [
				'rateLimits' => $data->rateLimits,
				'dos' => $data->dos,
				'bots' => $data->bots,
			]);
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}
		$this->entityManager->flush();

		$this->audit->record(
			sprintf('Updated application firewall protection policy to version %d', $version),
			'firewall_policy_updated',
			'Application',
			$application->getId(),
			['version' => $before],
			['version' => $version],
		);

		$data->applicationId = $application->getId();
		$data->version = $version;

		return $data;
	}
}
