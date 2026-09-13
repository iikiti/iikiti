<?php

namespace iikiti\CMS\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Remembers where a user was heading when authentication interrupted them.
 *
 * The value is stored under the key used by Symfony's form login, so the
 * destination survives the multi-factor challenge.
 */
class TargetPathStorage
{
	private const KEY_PATTERN = '_security.%s.target_path';

	public function __construct(
		private readonly RequestStack $requestStack,
		private readonly string $firewallName = 'main',
	) {
	}

	public function store(string $path): void
	{
		$this->getSession()->set($this->key(), $path);
	}

	/**
	 * Returns and clears the stored destination, or null when it is missing or
	 * not a safe internal path.
	 */
	public function pull(): ?string
	{
		$path = $this->getSession()->remove($this->key());

		if (!is_string($path) || '' === $path || false === $this->isInternalPath($path)) {
			return null;
		}

		return $path;
	}

	/**
	 * Rejects values that browsers would treat as another origin, such as
	 * protocol-relative ("//host") or backslash variants.
	 */
	private function isInternalPath(string $path): bool
	{
		return str_starts_with($path, '/') &&
			!str_starts_with($path, '//') &&
			!str_starts_with($path, '/\\');
	}

	private function key(): string
	{
		return sprintf(self::KEY_PATTERN, $this->firewallName);
	}

	private function getSession(): SessionInterface
	{
		return $this->requestStack->getSession();
	}
}
