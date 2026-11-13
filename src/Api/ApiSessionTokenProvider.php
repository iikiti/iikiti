<?php

declare(strict_types=1);

namespace iikiti\CMS\Api;

use iikiti\CMS\Async\FiberHttpClient;
use Symfony\Component\HttpFoundation\Request;

/** Exchanges an incoming Symfony session for its user-bound API credential. */
final readonly class ApiSessionTokenProvider
{
	public function __construct(private BusinessApiClient $api)
	{
	}

	public function tokenFor(FiberHttpClient $http, Request $request, string $siteHost, bool $collectDoctrineQueries = false): ?string
	{
		$token = $request->headers->get('X-AUTH-TOKEN');
		if (null !== $token && '' !== $token) {
			return $token;
		}

		$sessionCookie = $this->sessionCookie($request);
		if (null === $sessionCookie) {
			return null;
		}
		[$cookieName, $cookieValue] = $sessionCookie;
		// The token endpoint reads this same session, so release its lock before
		// making the nested request or the API will wait for this request to finish.
		$request->getSession()->save();

		try {
			$data = $this->api->requestAsync(
				$http,
				'POST',
				'/api/auth/session-token',
				headers: [
					'Cookie' => $cookieName.'='.$cookieValue,
					'X-SITE-HOST' => strtolower($siteHost),
					'X-SITE-SCHEME' => $request->getScheme(),
				],
				collectDoctrineQueries: $collectDoctrineQueries,
			);
		} catch (BusinessApiException $exception) {
			if (401 === $exception->statusCode) {
				return null;
			}
			throw $exception;
		}

		$sessionToken = $data['token'] ?? null;

		return is_string($sessionToken) && '' !== $sessionToken ? $sessionToken : null;
	}

	public function hasSessionCookie(Request $request): bool
	{
		return null !== $this->sessionCookie($request);
	}

	/** @return array{string,string}|null */
	private function sessionCookie(Request $request): ?array
	{
		if (!$request->hasSession()) {
			return null;
		}

		$cookieName = $request->getSession()->getName();
		$cookieValue = $request->cookies->get($cookieName);
		if (!is_string($cookieValue) || '' === $cookieValue) {
			return null;
		}

		return [$cookieName, $cookieValue];
	}
}
