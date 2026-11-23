<?php

declare(strict_types=1);

namespace iikiti\CMS\Api;

use iikiti\CMS\Async\FiberHttpClient;
use Symfony\Component\HttpFoundation\Request;

/** Exchanges an incoming Symfony session for its user-bound API credential. */
final readonly class ApiSessionTokenProvider
{
	public const string SESSION_CACHE_KEY = '_iikiti_api_session_token_cache';
	private const int DEFAULT_CACHE_TTL_SECONDS = 60;

	public function __construct(
		private BusinessApiClient $api,
		private int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
	) {
	}

	/**
	 * Synchronously returns a valid cached user-bound API token from the Symfony
	 * session when available, immediately releasing the session lock.
	 */
	public function cachedTokenFor(Request $request, string $siteHost): ?string
	{
		$sessionCookie = $this->sessionCookie($request);
		if (null === $sessionCookie) {
			return null;
		}
		[, $cookieValue] = $sessionCookie;
		$session = $request->getSession();
		try {
			$cache = $session->get(self::SESSION_CACHE_KEY);
			if (!is_array($cache)) {
				return null;
			}

			$entry = $cache[$this->cacheEntryKey($siteHost, $cookieValue)] ?? null;
			if (!is_array($entry)
				|| !is_string($entry['token'] ?? null)
				|| '' === $entry['token']
				|| !is_int($entry['expires_at'] ?? null)
				|| $entry['expires_at'] <= time()) {
				return null;
			}

			return $entry['token'];
		} finally {
			// Release the session lock on every path before launching downstream Fiber HTTP requests.
			$session->save();
		}
	}

	public function invalidateCachedToken(Request $request, string $siteHost): void
	{
		$sessionCookie = $this->sessionCookie($request);
		if (null === $sessionCookie) {
			return;
		}
		[, $cookieValue] = $sessionCookie;
		$session = $request->getSession();
		try {
			$cache = $session->get(self::SESSION_CACHE_KEY);
			if (!is_array($cache)) {
				return;
			}
			$key = $this->cacheEntryKey($siteHost, $cookieValue);
			if (!array_key_exists($key, $cache)) {
				return;
			}
			unset($cache[$key]);
			if ([] === $cache) {
				$session->remove(self::SESSION_CACHE_KEY);
			} else {
				$session->set(self::SESSION_CACHE_KEY, $cache);
			}
		} finally {
			$session->save();
		}
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
		$session = $request->getSession();
		// The token endpoint reads this same session, so release its lock before
		// making the nested request or the API will wait for this request to finish.
		$session->save();

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
		if (!is_string($sessionToken) || '' === $sessionToken) {
			return null;
		}

		if ($this->cacheTtlSeconds > 0) {
			try {
				$now = time();
				$cookieSuffix = ':'.hash('sha256', $cookieValue);
				$existing = $session->get(self::SESSION_CACHE_KEY);
				$cache = [];
				if (is_array($existing)) {
					foreach ($existing as $existingKey => $existingEntry) {
						if (is_string($existingKey)
							&& str_ends_with($existingKey, $cookieSuffix)
							&& is_array($existingEntry)
							&& is_int($existingEntry['expires_at'] ?? null)
							&& $existingEntry['expires_at'] > $now) {
							$cache[$existingKey] = $existingEntry;
						}
					}
				}
				$cache[$this->cacheEntryKey($siteHost, $cookieValue)] = [
					'token' => $sessionToken,
					'expires_at' => $now + $this->cacheTtlSeconds,
				];
				$session->set(self::SESSION_CACHE_KEY, $cache);
				$session->save();
			} catch (\Throwable) {
				// Session cache writes are best-effort; the exchanged token remains usable.
			}
		}

		return $sessionToken;
	}

	private function cacheEntryKey(string $siteHost, string $cookieValue): string
	{
		return strtolower($siteHost).':'.hash('sha256', $cookieValue);
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
