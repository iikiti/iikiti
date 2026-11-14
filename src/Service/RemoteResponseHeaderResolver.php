<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RemoteResponseHeaderResolver
{
	public const PATH = '/api/internal/response-headers/resolve';

	/**
	 * Request headers the consumer forwards for policy conditions. Rules may only
	 * condition on these names when the consumer is in use; this keeps unrelated
	 * request metadata (referrers, client addresses, custom identifiers) out of the
	 * signed internal request.
	 *
	 * @var list<string>
	 */
	public const FORWARDED_HEADERS = ['accept-language', 'user-agent', 'x-device'];

	public function __construct(
		private BusinessApiClient $api,
		#[Autowire('%kernel.secret%')] private string $secret,
	) {
	}

	/** @return array{rules:array<string,array<string,mixed>>,siteMode:string,vary:list<string>} */
	public function resolve(Request $request, Response $response): array
	{
		$base = parse_url($this->api->getBaseUrl());
		if (is_array($base) && strtolower((string) ($base['host'] ?? '')) === strtolower($request->getHost()) &&
			(int) ($base['port'] ?? (($base['scheme'] ?? '') === 'https' ? 443 : 80)) === $request->getPort()) {
			throw new \RuntimeException('INTERNAL_API_BASE_URL must target a separate API runtime.');
		}
		if ('' === trim($this->secret)) {
			throw new \RuntimeException('A shared APP_SECRET is required for response header resolution.');
		}
		$headers = [];
		foreach (self::FORWARDED_HEADERS as $name) {
			if (!$request->headers->has($name) || preg_match('/auth|cookie|token|secret|api[-_]?key/i', $name)) {
				continue;
			}
			$value = implode(', ', $request->headers->all($name));
			if (strlen($value) <= 4096 && !preg_match('/[\r\n]/', $value)) {
				$headers[$name] = $value;
			}
		}
		$payload = ['host' => $request->getHost(), 'scheme' => $request->getScheme(), 'method' => $request->getMethod(), 'path' => $request->getPathInfo(), 'status' => $response->getStatusCode(), 'headers' => $headers, 'time' => time()];
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		$result = $this->api->request('POST', self::PATH, body: ['payload' => $payload, 'signature' => hash_hmac('sha256', $json, $this->secret)]);
		if (!is_array($result['rules'] ?? null) || !is_string($result['siteMode'] ?? null) || !is_array($result['vary'] ?? null) ||
			!array_is_list($result['vary']) || array_filter($result['vary'], static fn ($name): bool => !is_string($name) || 1 !== preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name))) {
			throw new \UnexpectedValueException('Invalid response header resolver result.');
		}

		return $result;
	}
}
