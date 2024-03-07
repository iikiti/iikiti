<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\UserRepository;
use iikiti\CMS\Trait\MfaPreferencesTrait;
use iikiti\CMS\Trait\PreferentialTrait;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User entity.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'objects')]
class User extends DbObject implements
	PasswordAuthenticatedUserInterface,
	UserInterface
{
	use MfaPreferencesTrait;
	use PreferentialTrait;

	public const SITE_SPECIFIC = false;

	private array $roles = [];

	/**
	 * @return array<string>
	 */
	public function getEmails(): ?array
	{
		return $this->getProperties()->get('emails')?->getValue();
	}

	public function getUserIdentifier(): string
	{
		return $this->getUsername() ?? '';
	}

	public function __toString(): string
	{
		return $this->getUserIdentifier();
	}

	public function getRoles(bool $asEnum = false): array
	{
		$defaultRoles = UserRoleManager::getDefaultRoles();
		$currentSiteId = $this->siteRegistry::getCurrent()->getId();
		if (null === $currentSiteId) {
			throw new \Exception('Site cannot be null');
		}
		$roles = array_merge($defaultRoles, $this->getRegistrationRoles($currentSiteId));

		return $asEnum ? $roles : array_values(UserRoleManager::convertEnumsToStrings($roles));
	}

	public function getPassword(): ?string
	{
		$property = $this->getProperties()->get('password');

		return $property?->getValue();
	}

	public function getUsername(): ?string
	{
		$property = $this->getProperties()->get('username');

		return $property?->getValue();
	}

	public function eraseCredentials(): void
	{
	}

	public function registeredToSite(string|int|null $siteId): bool
	{
		return null === $siteId ?
			false :
			count(
				array_intersect_assoc(
					UserRoleManager::getAllRoles(),
					$this->getRegistrationRoles($siteId)
				)
			) > 0;
	}

	public function getSiteRoles(string|int $siteId): array
	{
		$property = $this->getProperties()->get('roles');

		return UserRoleManager::convertStringsToEnums(
			$property?->getValue()[$siteId] ?? []
		);
	}

	public function getGlobalRoles(): array
	{
		$property = $this->getProperties()->get('roles');

		return UserRoleManager::convertStringsToEnums(
			$property?->getValue()[0] ?? []
		);
	}

	public function getRegistrationRoles(string|int $siteId): array
	{
		return array_merge($this->getGlobalRoles(), $this->getSiteRoles($siteId));
	}

	public function getPreferredTwoFactorProvider(): ?string
	{
		return 'email';
	}
}
