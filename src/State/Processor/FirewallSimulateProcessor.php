<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\FirewallSimulateResource;
use iikiti\CMS\ApiResource\Output\FirewallSimulateOutput;
use iikiti\CMS\Security\Firewall\Admin\FirewallSimulator;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProcessorInterface<FirewallSimulateResource,FirewallSimulateOutput> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallSimulateProcessor implements ProcessorInterface
{
	private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
	private const MAX_HEADERS = 64;

	public function __construct(private FirewallSimulator $simulator)
	{
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallSimulateOutput
	{
		// Site scope is the request-host site; global simulation (no siteId) evaluates the no-site policy.
		$site = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)
			: null;

		$ip = IpCidrNormalizer::normalizeIp($data->ip);
		if (null === $ip) {
			throw new BadRequestHttpException('ip must be a valid IPv4 or IPv6 address.');
		}
		$method = strtoupper($data->method);
		if (!in_array($method, self::ALLOWED_METHODS, true)) {
			throw new BadRequestHttpException('method is not supported by the simulator.');
		}
		if (!str_starts_with($data->path, '/') || strlen($data->path) > 2048) {
			throw new BadRequestHttpException('path must be an absolute path of at most 2048 characters.');
		}
		if (count($data->headers) > self::MAX_HEADERS) {
			throw new BadRequestHttpException('Too many headers supplied to the simulator.');
		}

		$headers = $data->headers;
		if ('' !== $data->userAgent) {
			$headers['User-Agent'] = $data->userAgent;
		}

		$result = $this->simulator->simulate($site, $ip, $method, $data->path, $headers, $data->authenticated);

		return new FirewallSimulateOutput(
			ip: $ip,
			method: $method,
			path: $data->path,
			authenticated: $data->authenticated,
			decision: $result['decision'],
			ipMatch: $result['ipMatch'],
			botMatch: $result['botMatch'],
			dosResult: $result['dosResult'],
			rateLimits: $result['rateLimits'],
			wafFindings: $result['wafFindings'],
		);
	}
}
