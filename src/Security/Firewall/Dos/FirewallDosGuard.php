<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Dos;

use Symfony\Component\HttpFoundation\Request;

/**
 * Rejects structurally abusive requests before body or upload parsing.
 *
 * Only inspects request metadata (headers, URI, query, cookies, declared length) so it runs
 * before any stream is read. Limits come from the resolved DoS policy.
 */
final class FirewallDosGuard
{
	private const BODY_METHODS = ['GET', 'HEAD'];
	private const STATUS_BAD_REQUEST = 400;
	private const STATUS_PAYLOAD_TOO_LARGE = 413;

	/** @param array<string,mixed> $dosConfig */
	public function inspect(Request $request, array $dosConfig, string $routeGroup): FirewallDosResult
	{
		if (false === ($dosConfig['enabled'] ?? true)) {
			return FirewallDosResult::allowed();
		}

		$findings = [
			...$this->checkHeaders($request, $dosConfig),
			...$this->checkUriAndQuery($request, $dosConfig),
			...$this->checkCookies($request, $dosConfig),
			...$this->checkBody($request, $dosConfig, $routeGroup),
		];

		if ([] === $findings) {
			return FirewallDosResult::allowed();
		}

		return new FirewallDosResult(true, $this->statusFor($findings), $findings);
	}

	/**
	 * @param array<string,mixed> $dosConfig
	 *
	 * @return list<string>
	 */
	private function checkHeaders(Request $request, array $dosConfig): array
	{
		$headers = $request->headers->all();
		$findings = [];
		if (count($headers) > $this->int($dosConfig, 'maxHeaderCount')) {
			$findings[] = 'dos:header-count';
		}
		$bytes = 0;
		foreach ($headers as $name => $values) {
			$bytes += strlen((string) $name);
			foreach ((array) $values as $value) {
				$bytes += strlen((string) $value);
			}
		}
		if ($bytes > $this->int($dosConfig, 'maxHeaderBytes')) {
			$findings[] = 'dos:header-bytes';
		}

		return $findings;
	}

	/**
	 * @param array<string,mixed> $dosConfig
	 *
	 * @return list<string>
	 */
	private function checkUriAndQuery(Request $request, array $dosConfig): array
	{
		$findings = [];
		if (strlen($request->getRequestUri()) > $this->int($dosConfig, 'maxUriLength')) {
			$findings[] = 'dos:uri-length';
		}
		if ($this->countQueryParams($request->query->all()) > $this->int($dosConfig, 'maxQueryParams')) {
			$findings[] = 'dos:query-params';
		}

		return $findings;
	}

	/**
	 * @param array<string,mixed> $dosConfig
	 *
	 * @return list<string>
	 */
	private function checkCookies(Request $request, array $dosConfig): array
	{
		$cookies = $request->cookies->all();
		$findings = [];
		if (count($cookies) > $this->int($dosConfig, 'maxCookieCount')) {
			$findings[] = 'dos:cookie-count';
		}
		$bytes = 0;
		foreach ($cookies as $name => $value) {
			$bytes += strlen((string) $name) + strlen(is_scalar($value) ? (string) $value : '');
		}
		if ($bytes > $this->int($dosConfig, 'maxCookieBytes')) {
			$findings[] = 'dos:cookie-bytes';
		}

		return $findings;
	}

	/**
	 * @param array<string,mixed> $dosConfig
	 *
	 * @return list<string>
	 */
	private function checkBody(Request $request, array $dosConfig, string $routeGroup): array
	{
		$declared = $request->headers->get('Content-Length');
		$hasBody = null !== $declared && '' !== $declared && (int) $declared > 0;
		if (in_array($request->getMethod(), self::BODY_METHODS, true)) {
			return $hasBody ? ['dos:unexpected-body'] : [];
		}
		if (!$hasBody) {
			return [];
		}
		$limits = is_array($dosConfig['maxBodyBytesByGroup'] ?? null) ? $dosConfig['maxBodyBytesByGroup'] : [];
		$limit = $limits[$this->bodyGroup($routeGroup)] ?? null;

		return is_int($limit) && (int) $declared > $limit ? ['dos:body-size'] : [];
	}

	/**
	 * Maps route groups onto the body-limit buckets the policy defines.
	 */
	private function bodyGroup(string $routeGroup): string
	{
		return match ($routeGroup) {
			'auth', 'mfa' => 'auth',
			'forms' => 'forms',
			'public' => 'public',
			default => 'api',
		};
	}

	/** @param array<mixed> $params */
	private function countQueryParams(array $params): int
	{
		$count = 0;
		foreach ($params as $value) {
			++$count;
			if (is_array($value)) {
				$count += $this->countQueryParams($value);
			}
		}

		return $count;
	}

	/** @param list<string> $findings */
	private function statusFor(array $findings): int
	{
		return in_array('dos:body-size', $findings, true) || in_array('dos:header-bytes', $findings, true)
			? self::STATUS_PAYLOAD_TOO_LARGE
			: self::STATUS_BAD_REQUEST;
	}

	/** @param array<string,mixed> $dosConfig */
	private function int(array $dosConfig, string $key): int
	{
		return is_int($dosConfig[$key] ?? null) ? $dosConfig[$key] : PHP_INT_MAX;
	}
}
