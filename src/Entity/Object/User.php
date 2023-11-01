<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\UserRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: "objects")]
class User extends DbObject implements UserInterface, PasswordAuthenticatedUserInterface
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

    public function getRoles(bool $asObject = false): array {
		$roles = array_merge(
			((array) $this->getProperties()->get('roles')) ?? [],
			UserRoleManager::getDefaultRoles()
		);
        return $roles;
    }

    public function getPassword(): null|string {
        return $this->getProperties()->get('password')?->getValue();
    }

    public function getSalt(): string {
        return '';
    }

    public function getUsername(): ?string {
		return $this->getProperties()->get('username')?->getValue();
    }

    public function eraseCredentials(): void {
        // TODO: Implement eraseCredentials() method.
    }

    public function registeredToSite(string $siteId): bool {
        //TODO: Fix: return isset($this->getMeta()->get(0)->json_content['roles'][$siteId]);
		return true;
    }

    public function getSiteRoles(string $siteId): array {
        //TODO: Fix: return $this->getMeta()->get(0)->json_content['roles'][$siteId] ?? [];
    }
}
