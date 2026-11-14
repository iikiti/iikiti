<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallTrustedAutomationResource;
use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\State\Mapper\FirewallTrustedAutomationMapper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<FirewallTrustedAutomationResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallTrustedAutomationProvider implements ProviderInterface
{
	private const GLOBAL_SITE_ID = 0;

	public function __construct(
		private FirewallTrustedAutomationRepository $automations,
		private FirewallTrustedAutomationMapper $mapper,
	) {
	}

	/** @return FirewallTrustedAutomationResource|list<FirewallTrustedAutomationResource> */
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirewallTrustedAutomationResource|array
	{
		$siteId = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: self::GLOBAL_SITE_ID;

		if ($operation instanceof GetCollection) {
			return array_map(
				fn (FirewallTrustedAutomation $automation): FirewallTrustedAutomationResource => $this->mapper->toResource($automation),
				$this->automations->findForScope($siteId),
			);
		}

		$automation = $this->automations->find($uriVariables['id'] ?? '');
		if (!$automation instanceof FirewallTrustedAutomation || (string) $automation->getSiteId() !== (string) $siteId) {
			throw new NotFoundHttpException('Trusted automation not found.');
		}

		return $this->mapper->toResource($automation);
	}
}
