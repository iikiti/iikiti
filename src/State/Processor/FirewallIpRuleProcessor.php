<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\FirewallIpRuleResource;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Security\Firewall\Admin\FirewallIpRuleService;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\State\Mapper\FirewallIpRuleMapper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<FirewallIpRuleResource,FirewallIpRuleResource> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallIpRuleProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallIpRuleService $rules,
		private FirewallIpRuleMapper $mapper,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallIpRuleResource
	{
		$siteId = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: FirewallIpRuleService::GLOBAL_SITE_ID;

		$attributes = $this->attributesFrom($data);
		try {
			if (isset($uriVariables['id'])) {
				$rule = $this->rules->find($siteId, $uriVariables['id']);
				if (null === $rule) {
					throw new NotFoundHttpException('Firewall IP rule not found.');
				}
				$before = $this->snapshot($rule);
				$rule = $this->rules->update($rule, $attributes);
				$this->audit->record(
					sprintf('Updated firewall IP rule %s', $rule->getCidr()),
					'firewall_ip_rule_updated',
					FirewallIpRule::class,
					$rule->getId(),
					$before,
					$this->snapshot($rule),
				);
			} else {
				$rule = $this->rules->create($siteId, $attributes);
				$this->audit->record(
					sprintf('Created firewall IP rule %s', $rule->getCidr()),
					'firewall_ip_rule_created',
					FirewallIpRule::class,
					$rule->getId(),
					null,
					$this->snapshot($rule),
				);
			}
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}

		return $this->mapper->toResource($rule);
	}

	/** @return array<string,mixed> */
	private function attributesFrom(mixed $data): array
	{
		if (!$data instanceof FirewallIpRuleResource) {
			throw new BadRequestHttpException('Firewall IP rule payload is invalid.');
		}

		return [
			'cidr' => $data->cidr,
			'action' => $data->action,
			'inheritance' => $data->inheritance,
			'reason' => $data->reason,
			'enabled' => $data->enabled,
			'priority' => $data->priority,
			'expiresAt' => $data->expiresAt,
		];
	}

	/** @return array<string,mixed> */
	private function snapshot(FirewallIpRule $rule): array
	{
		return [
			'cidr' => $rule->getCidr(),
			'action' => $rule->getAction(),
			'inheritance' => $rule->getInheritance(),
			'enabled' => $rule->isEnabled(),
			'priority' => $rule->getPriority(),
		];
	}
}
