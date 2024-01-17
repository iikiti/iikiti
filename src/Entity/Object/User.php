<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\UserRepository;
use iikiti\MfaBundle\Entity\UserInterface as MfaUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'objects')]
class User extends DbObject implements PasswordAuthenticatedUserInterface, MfaUserInterface, UserInterface
{
	public const SITE_SPECIFIC = false;

	/** @psalm-suppress PropertyNotSetInConstructor */
	protected string|int|null $currentSiteId = null;

	private array $roles = [];

	public function setCurrentSiteId(string|int|null $siteId): void
	{
		$this->currentSiteId = $siteId;
	}

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

	/**
	 * @return array<int,string>|array<string,EnumCase>
	 */
	/** @psalm-suppress ImplementedReturnTypeMismatch */
	public function getRoles(bool $asEnum = false): array
	{
		$defaultRoles = UserRoleManager::getDefaultRoles();
		if (null === $this->currentSiteId) {
			throw new \Exception('Site cannot be null');
		}
		$roles = array_merge($defaultRoles, $this->getRegistrationRoles($this->currentSiteId));

		return $asEnum ? $roles : array_values(UserRoleManager::convertEnumsToStrings($roles));
	}

	public function getPassword(): null|string
	{
		/** @var ObjectProperty<string>|null $property */
		$property = $this->getProperties()->get('password');

		return $property?->getValue();
	}

	public function getUsername(): ?string
	{
		/** @var ObjectProperty<string>|null $property */
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
		/** @var ObjectProperty<array>|null $property */
		$property = $this->getProperties()->get('roles');

		return UserRoleManager::convertStringsToEnums(
			$property?->getValue()[$siteId] ?? []
		);
	}

	public function getGlobalRoles(): array
	{
		/** @var ObjectProperty<array>|null $property */
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

	public function getMultifactorPreferences(): array|null
	{
		if (false == $this->getProperties()->containsKey(self::MFA_KEY)) {
			return []; // User does not have MFA preferences
		}

		/** @var ObjectProperty<array>|null $property */
		$property = $this->getProperties()->get(self::MFA_KEY);

		return $property?->getValue();
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		$this->setProperty(self::MFA_KEY, $preferences);
	}
}
