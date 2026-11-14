<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ResponseHeaderApplicator
{
	private const SECURITY_HEADERS = [
		'content-security-policy' => SecurityHeaderPolicy::HEADER_CSP,
		'content-security-policy-report-only' => SecurityHeaderPolicy::HEADER_CSP_REPORT_ONLY,
		'strict-transport-security' => SecurityHeaderPolicy::HEADER_HSTS,
		'x-frame-options' => SecurityHeaderPolicy::HEADER_FRAME_OPTIONS,
		'x-content-type-options' => SecurityHeaderPolicy::HEADER_CONTENT_TYPE_OPTIONS,
		'referrer-policy' => SecurityHeaderPolicy::HEADER_REFERRER_POLICY,
		'permissions-policy' => SecurityHeaderPolicy::HEADER_PERMISSIONS_POLICY,
		'cross-origin-opener-policy' => SecurityHeaderPolicy::HEADER_COOP,
		'cross-origin-embedder-policy' => SecurityHeaderPolicy::HEADER_COEP,
		'cross-origin-resource-policy' => SecurityHeaderPolicy::HEADER_CORP,
	];

	public function __construct(
		private SecurityHeaderPolicy $security,
		#[Autowire('%iikiti_security_headers%')] private string $envMode,
	) {
	}

	/** @param array<string,array{name:string,action:string,value?:string}> $rules */
	public function apply(Request $request, Response $response, array $rules, string $siteMode): void
	{
		$mode = $this->security->effectiveMode($this->envMode, $siteMode, $request->isSecure(), $response->isSuccessful());
		$nonce = 'on' === $mode ? base64_encode(random_bytes(16)) : null;
		if (null !== $nonce) {
			foreach ($this->security->renderHeaders([], $mode, $nonce) as $name => $value) {
				$value = $this->security->renderHeaders([['name' => $name, 'mode' => 'on', 'value' => $value]], $mode, $nonce)[$name] ?? $value;
				$response->headers->set($name, $value);
			}
		}

		foreach ($rules as $key => $rule) {
			$name = $rule['name'];
			$securityName = self::SECURITY_HEADERS[strtolower($key)] ?? null;
			if (null !== $securityName && 'off' === $mode) {
				continue;
			}
			if ('remove' === $rule['action']) {
				$response->headers->remove($name);
				continue;
			}
			$value = $rule['value'] ?? '';
			if (null !== $securityName) {
				$rendered = $this->security->renderHeaders([['name' => $securityName, 'mode' => 'on', 'value' => $value]], $mode, $nonce);
				$value = $rendered[$securityName] ?? '';
			}
			$response->headers->set($name, $value);
		}

		if (null !== $nonce && !$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
			$html = $response->getContent();
			if (is_string($html) && '' !== $html) {
				$html = preg_replace_callback('/<(script|style)\b([^>]*?)\/?>/i', static function (array $matches) use ($nonce): string {
					if (preg_match('/\bnonce\s*=/i', $matches[2])) {
						return $matches[0];
					}

					return sprintf('<%s%s nonce="%s">', $matches[1], $matches[2], $nonce);
				}, $html);
				if (null !== $html) {
					$response->setContent($html);
				}
			}
		}
	}
}
