<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallIpBanResource;
use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<FirewallIpBanResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallIpBanProvider implements ProviderInterface
{
	public function __construct(private FirewallIpBanRepository $bans)
	{
	}

	/** @return FirewallIpBanResource|list<FirewallIpBanResource> */
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirewallIpBanResource|array
	{
		$siteId = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: null;

		if (!$operation instanceof GetCollection) {
			$ban = $this->bans->find($uriVariables['id'] ?? '');
			if (!$ban instanceof FirewallIpBan) {
				throw new NotFoundHttpException('Firewall IP ban not found.');
			}
			if (null !== $siteId && (string) $ban->getSiteId() !== (string) $siteId) {
				throw new NotFoundHttpException('Firewall IP ban not found.');
			}

			return $this->toResource($ban);
		}

		$bans = $this->bans->findActiveForSiteAndGlobal($siteId);

		return array_map($this->toResource(...), $bans);
	}

	private function toResource(FirewallIpBan $ban): FirewallIpBanResource
	{
		return new FirewallIpBanResource(
			id: $ban->getId(),
			siteId: $ban->getSiteId(),
			cidr: $ban->getCidr(),
			reasonCode: $ban->getReasonCode(),
			reasonMessage: $ban->getReasonMessage(),
			strikes: $ban->getStrikes(),
			expiresAt: $ban->getExpiresAt()?->format(\DateTimeInterface::ATOM),
			createdAt: $ban->getCreatedAt()->format(\DateTimeInterface::ATOM),
		);
	}
}
