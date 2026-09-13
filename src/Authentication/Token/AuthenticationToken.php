<?php

namespace iikiti\CMS\Authentication\Token;

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Proxy token placed in front of the real login token.
 *
 * Until the multi-factor challenge has been completed the proxy reports
 * itself as unauthenticated, so voters deny access even though the user has
 * already supplied valid credentials. On success the proxy is marked
 * authenticated and access is allowed.
 */
class AuthenticationToken extends AbstractToken implements TokenInterface
{
	private ?TokenInterface $associatedToken = null;

	private bool $authenticated = false;

	/**
	 * @param array<int,string> $roles
	 */
	public function __construct(array $roles = [])
	{
		parent::__construct($roles);
	}

	public function setAssociatedToken(TokenInterface $token): void
	{
		$this->associatedToken = $token;
	}

	public function getUser(): ?UserInterface
	{
		return $this->associatedToken?->getUser();
	}

	public function setUser(UserInterface $user): void
	{
		$this->associatedToken?->setUser($user);
	}

	public function isAuthenticated(): bool
	{
		return $this->authenticated;
	}

	public function setIsAuthenticated(bool $authenticated): void
	{
		$this->authenticated = $authenticated;
	}

	/**
	 * A pending challenge must not grant roles, otherwise role-based checks
	 * would authorise the user before the second factor is satisfied.
	 *
	 * @return array<int,string>
	 */
	public function getRoleNames(): array
	{
		if (false === $this->authenticated) {
			return [];
		}

		return $this->associatedToken?->getRoleNames() ?? [];
	}

	public function getUserIdentifier(): string
	{
		return $this->getUser()?->getUserIdentifier() ?? '';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getAttributes(): array
	{
		return $this->associatedToken?->getAttributes() ?? [];
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function setAttributes(array $attributes): void
	{
		$this->associatedToken?->setAttributes($attributes);
	}

	public function hasAttribute(string $name): bool
	{
		return $this->associatedToken?->hasAttribute($name) ?? false;
	}

	public function getAttribute(string $name): mixed
	{
		return $this->associatedToken?->getAttribute($name);
	}

	public function setAttribute(string $name, mixed $value): void
	{
		$this->associatedToken?->setAttribute($name, $value);
	}

	/**
	 * @return array<int,mixed>
	 */
	public function __serialize(): array
	{
		// The flag must be serialized explicitly so a completed challenge
		// survives the session round-trip; the parent stores no such value.
		return [$this->associatedToken, $this->authenticated, parent::__serialize()];
	}

	/**
	 * @param array<int,mixed> $data
	 */
	public function __unserialize(array $data): void
	{
		[$this->associatedToken, $this->authenticated, $parentData] = $data;
		parent::__unserialize($parentData);
	}
}
