<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\UserRepository;
use iikiti\MfaBundle\Authentication\User\Property;
use iikiti\MfaBundle\Entity\UserInterface as MfaUserInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
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
		return $this->getProperties()->get('password')?->getValue();
	}

	public function getUsername(): ?string
	{
		return $this->getProperties()->get('username')?->getValue();
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
		return UserRoleManager::convertStringsToEnums(
			$this->getProperties()->get('roles')?->getValue()[$siteId] ?? []
		);
	}

	public function getGlobalRoles(): array
	{
		return UserRoleManager::convertStringsToEnums(
			$this->getProperties()->get('roles')?->getValue()[0] ?? []
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

	public function getMultifactorPreferences(): array
	{
		if (false == $this->getProperties()->containsKey(Property::KEY)) {
			return []; // User does not have MFA preferences
		}

		/** @var Property|\__PHP_Incomplete_Class|false $mfaProperty */
		$mfaProperty = unserialize($this->getProperties()->get(Property::KEY)->getValue());

		if (false === $mfaProperty || false == ($mfaProperty instanceof Property)) {
			throw new AuthenticationException('User has invalid or missing MFA preferences');
		}

		return [];
	}
}
