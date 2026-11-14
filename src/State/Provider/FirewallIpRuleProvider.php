<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallIpRuleResource;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Security\Firewall\Admin\FirewallIpRuleService;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\State\Mapper\FirewallIpRuleMapper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<FirewallIpRuleResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallIpRuleProvider implements ProviderInterface
{
	public function __construct(
		private FirewallIpRuleService $rules,
		private FirewallIpRuleMapper $mapper,
	)
	{
	}

	/** @return FirewallIpRuleResource|list<FirewallIpRuleResource> */
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirewallIpRuleResource|array
	{
		$siteId = $this->scopeFor($uriVariables);

		if ($operation instanceof GetCollection) {
			return array_map(fn (FirewallIpRule $rule): FirewallIpRuleResource => $this->mapper->toResource($rule), $this->rules->listForScope($siteId));
		}

		$rule = $this->rules->find($siteId, $uriVariables['id'] ?? '');
		if (null === $rule) {
			throw new NotFoundHttpException('Firewall IP rule not found.');
		}

		return $this->mapper->toResource($rule);
	}

	/**
	 * Site routes resolve the current site (after verifying the URI site id); global routes use scope 0.
	 *
	 * @param array<string,mixed> $uriVariables
	 */
	private function scopeFor(array $uriVariables): int|string
	{
		if (!array_key_exists('siteId', $uriVariables)) {
			return FirewallIpRuleService::GLOBAL_SITE_ID;
		}

		return FirewallSiteScope::assertCurrent($uriVariables)->getId();
	}
}
