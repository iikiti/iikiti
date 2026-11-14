<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\FirewallSiteProtectionResource;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProcessorInterface<FirewallSiteProtectionResource,FirewallSiteProtectionResource> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallSiteProtectionProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallProtectionPolicyStore $policies,
		private EntityManagerInterface $entityManager,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallSiteProtectionResource
	{
		$site = FirewallSiteScope::assertCurrent($uriVariables);
		$before = $this->policies->getSitePolicy($site)['version'];

		try {
			$version = $this->policies->setSitePolicy($site, [
				'rateLimits' => $data->rateLimits,
				'dos' => $data->dos,
				'bots' => $data->bots,
			]);
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}
		$this->entityManager->flush();

		$this->audit->record(
			sprintf('Updated site firewall protection policy to version %d', $version),
			'firewall_policy_updated',
			'Site',
			$site->getId(),
			['version' => $before],
			['version' => $version],
		);

		$data->siteId = $site->getId();
		$data->version = $version;
		$data->effective = $this->policies->getResolvedPolicy($this->applicationFor($site), $site);

		return $data;
	}

	private function applicationFor(Site $site): ?Application
	{
		/** @var SiteRepository $sites */
		$sites = $this->entityManager->getRepository(Site::class);

		return $sites->getApplicationBySite($site);
	}
}
