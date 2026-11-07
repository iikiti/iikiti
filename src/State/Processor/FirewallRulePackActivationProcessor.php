<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\FirewallRulePackActivationResource;
use iikiti\CMS\Security\Firewall\FirewallRulePackStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<FirewallRulePackActivationResource,FirewallRulePackActivationResource> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallRulePackActivationProcessor implements ProcessorInterface
{
	public function __construct(private FirewallRulePackStore $packs)
	{
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallRulePackActivationResource
	{
		$key = $uriVariables['key'] ?? null;
		if (!is_string($key) || $key !== $data->key || '' === $data->version) {
			throw new BadRequestHttpException('Firewall rule-pack activation request is invalid.');
		}
		try {
			$this->packs->activateVersion($key, $data->version);
		} catch (\InvalidArgumentException $exception) {
			throw new UnprocessableEntityHttpException('Firewall rule-pack version cannot be activated.', $exception);
		} catch (\RuntimeException $exception) {
			throw new NotFoundHttpException('Firewall rule-pack version was not found.', $exception);
		}
		$data->success = true;

		return $data;
	}
}
