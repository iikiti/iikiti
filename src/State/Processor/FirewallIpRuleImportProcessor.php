<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\FirewallIpRuleImportResource;
use iikiti\CMS\ApiResource\FirewallIpRuleResource;
use iikiti\CMS\ApiResource\Output\FirewallIpRuleImportOutput;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Security\Firewall\Admin\FirewallIpRuleService;
use iikiti\CMS\State\Mapper\FirewallIpRuleMapper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProcessorInterface<FirewallIpRuleImportResource,FirewallIpRuleImportOutput> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallIpRuleImportProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallIpRuleService $rules,
		private FirewallIpRuleMapper $mapper,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallIpRuleImportOutput
	{
		if ([] === $data->items) {
			throw new BadRequestHttpException('Import requires at least one rule in items.');
		}

		$items = [];
		foreach ($data->items as $item) {
			if (!is_string($item['cidr'] ?? null) || '' === $item['cidr']) {
				throw new BadRequestHttpException('Every import item requires a cidr string.');
			}
			$items[] = $item;
		}

		try {
			$created = $this->rules->importAtomically(FirewallIpRuleService::GLOBAL_SITE_ID, $items);
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}

		$this->audit->record(
			sprintf('Imported %d global firewall IP rules', count($created)),
			'firewall_ip_rules_imported',
			FirewallIpRule::class,
			null,
			null,
			['importedCount' => count($created)],
			['count' => count($created)],
		);

		return new FirewallIpRuleImportOutput(
			importedCount: count($created),
			rules: array_map(fn (FirewallIpRule $rule): FirewallIpRuleResource => $this->mapper->toResource($rule), $created),
		);
	}
}
