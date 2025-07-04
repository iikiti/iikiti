<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\UserRepository;
use iikiti\CMS\Trait\MfaPreferencesTrait;
use iikiti\CMS\Trait\PreferentialTrait;
use Override;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User entity.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class User extends DbObject implements
	PasswordAuthenticatedUserInterface,
	UserInterface
{
	use MfaPreferencesTrait;
	use PreferentialTrait;

	public const bool SITE_SPECIFIC = false;

	private array $roles = [];
	private ?Site $currentSite = null;

	/**
	 * @return array<string>
	 */
	public function getEmails(): ?array
	{
		return $this->getProperties()->get('emails')?->getValue();
	}

	public function setCurrentSite(Site $site): void
	{
		$this->currentSite = $site;
	}

	/**
     * Returns the identifier for this user (e.g. username or email address).
     *
     * @return non-empty-string
     */
	#[Override]
	public function getUserIdentifier(): string
	{
		$username = $this->getUsername();
		if (null === $username) {
			throw new UserNotFoundException('No username returned.');
		}

		return $username;
	}

	public function __toString(): string
	{
		return $this->getUserIdentifier();
	}

	#[Override]
	public function getRoles(bool $asEnum = false): array
	{
		$defaultRoles = UserRoleManager::getDefaultRoles();
		if (null === $this->currentSite) {
			// Or handle this case gracefully, e.g., by returning only default roles
			throw new \LogicException('Cannot get roles without a site context.');
		}
		$siteId = $this->currentSite->getId();
		if (null === $siteId) {
			throw new \LogicException('Site ID cannot be null when getting roles.');
		}
		$roles = array_merge(
			$defaultRoles,
			$this->getRegistrationRoles($siteId)
		);

		return $asEnum ? $roles : array_values(
			UserRoleManager::convertEnumsToStrings($roles)
		);
	}

	#[Override()]
	public function getPassword(): ?string
	{
        return $this->getProperties()->get('password')?->getValue();
	}

	/**
     * Returns the identifier for this user (e.g. username or email address).
     *
     * @return ?non-empty-string
     */
	public function getUsername(): ?string
	{
        return $this->getProperties()->get('username')?->getValue();
	}

	#[Override()]
	public function eraseCredentials(): void
	{
		//TODO: Implement eraseCredentials
	}

	public function registeredToSite(string|int|null $siteId): bool
	{
		return !(null === $siteId) && count(
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
