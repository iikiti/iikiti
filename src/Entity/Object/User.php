<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\UserRepository;
use InvalidArgumentException;
use Scheb\TwoFactorBundle\Model\PreferredProviderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: "objects")]
class User
	extends DbObject
	implements UserInterface, PasswordAuthenticatedUserInterface,
		PreferredProviderInterface
{

	const SITE_SPECIFIC = false;

    private array $roles = [];

	/**
	 * @return array<string>
	 */
    function getEmails(): ?array {
		return $this->getProperties()->get('emails')?->getValue();
    }

    function getUserIdentifier(): string {
        return $this->getUsername() ?? '';
    }

    public function __toString(): string {
        return $this->getUserIdentifier();
    }

	/**
	 * @return array<int,string>|array<string,EnumCase>
	 */
	/** @psalm-suppress ImplementedReturnTypeMismatch */
    public function getRoles(bool $asEnum = false): array {
		$siteId = SiteRegistry::getCurrentSite()->getId() ??
			throw new InvalidArgumentException('Site cannot be null');
		$defaultRoles = UserRoleManager::getDefaultRoles();
		$roles = array_merge($defaultRoles, $this->getRegistrationRoles($siteId));
        return $asEnum ? $roles : array_values(UserRoleManager::convertEnumsToStrings($roles));
    }

    public function getPassword(): null|string {
        return $this->getProperties()->get('password')?->getValue();
    }

    public function getUsername(): ?string {
		return $this->getProperties()->get('username')?->getValue();
    }

    public function eraseCredentials(): void {
        
    }

    public function registeredToSite(string|int|null $siteId): bool {
		return $siteId === null ?
			false :
			count(
				array_intersect_assoc(
					UserRoleManager::getAllRoles(),
					$this->getRegistrationRoles($siteId)
				)
			) > 0;
    }

    public function getSiteRoles(string|int $siteId): array {
        return UserRoleManager::convertStringsToEnums(
			$this->getProperties()->get('roles')?->getValue()[$siteId] ?? []
		);
    }

	public function getGlobalRoles(): array {
        return UserRoleManager::convertStringsToEnums(
			$this->getProperties()->get('roles')?->getValue()[0] ?? []
		);
    }

	public function getRegistrationRoles(string|int $siteId): array {
		return array_merge($this->getGlobalRoles(), $this->getSiteRoles($siteId));
	}

	public function getPreferredTwoFactorProvider(): ?string {
		dd('test');
		return 'email';
	}

}
