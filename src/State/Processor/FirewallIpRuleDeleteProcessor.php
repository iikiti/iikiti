<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Security\Firewall\Admin\FirewallIpRuleService;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<mixed,null> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallIpRuleDeleteProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallIpRuleService $rules,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
	{
		$siteId = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: FirewallIpRuleService::GLOBAL_SITE_ID;

		$rule = $this->rules->find($siteId, $uriVariables['id'] ?? '');
		if (null === $rule) {
			throw new NotFoundHttpException('Firewall IP rule not found.');
		}

		$cidr = $rule->getCidr();
		$ruleId = $rule->getId();
		$this->rules->delete($rule);
		$this->audit->record(
			sprintf('Deleted firewall IP rule %s', $cidr),
			'firewall_ip_rule_deleted',
			FirewallIpRule::class,
			$ruleId,
		);

		return null;
	}
}
