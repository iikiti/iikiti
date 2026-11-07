<?php

declare(strict_types=1);

namespace iikiti\CMS\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class GuestApiUser implements UserInterface
{
	public function __construct(
		private string $siteHost,
		private string $identifier,
	) {
	}

	public function getSiteHost(): string
	{
		return $this->siteHost;
	}

	public function getUserIdentifier(): string
	{
		return $this->identifier;
	}

	public function getRoles(): array
	{
		return ['ROLE_NON_MEMBER'];
	}

	public function eraseCredentials(): void
	{
	}
}
