<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<mixed,null> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallTrustedAutomationDeleteProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallTrustedAutomationRepository $automations,
		private EntityManagerInterface $entityManager,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
	{
		$siteId = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: 0;

		$automation = $this->automations->find($uriVariables['id'] ?? '');
		if (!$automation instanceof FirewallTrustedAutomation || (string) $automation->getSiteId() !== (string) $siteId) {
			throw new NotFoundHttpException('Trusted automation not found.');
		}

		$handle = $automation->getHandle();
		$automationId = $automation->getId();
		$this->entityManager->remove($automation);
		$this->entityManager->flush();
		$this->audit->record(
			sprintf('Deleted trusted automation %s', $handle),
			'firewall_automation_deleted',
			FirewallTrustedAutomation::class,
			$automationId,
		);

		return null;
	}
}
