<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\FirewallSitePolicyResource;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use iikiti\CMS\Security\Firewall\FirewallSitePolicyStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<FirewallSitePolicyResource,FirewallSitePolicyResource> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallSitePolicyProcessor implements ProcessorInterface
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private FirewallRuleValidator $validator,
		private FirewallSitePolicyStore $policies,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallSitePolicyResource
	{
		$site = SiteRegistry::getCurrent();
		if ((string) $site->getId() !== (string) ($uriVariables['siteId'] ?? '')) {
			throw new NotFoundHttpException('Site firewall policy not found.');
		}
		try {
			$rules = $this->validator->validateRules($data->rules);
			$idPrefix = sprintf('site.%s:', (string) $site->getId());
			foreach ($rules as $rule) {
				if (str_starts_with($rule['id'], 'core:') || str_contains($rule['id'], ':')) {
					throw new \InvalidArgumentException('Site rule IDs cannot use reserved core or pack namespaces.');
				}
				if (strlen($idPrefix.$rule['id']) > 128) {
					throw new \InvalidArgumentException('Namespaced site firewall rule ID exceeds the maximum length.');
				}
			}
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}

		$this->policies->setRules($site, $rules);
		$this->entityManager->persist($site);
		$this->entityManager->flush();

		return new FirewallSitePolicyResource($site->getId(), $rules);
	}
}
