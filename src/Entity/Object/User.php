<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\UserRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: "objects")]
class User extends DbObject implements UserInterface, PasswordAuthenticatedUserInterface
{

    private array $roles = [];

    function getEmails() {
        //TODO: Fix: return $this->getMeta()->first()->emails;
    }

    function getUserIdentifier(): string
    {
        return $this->getEmail();
    }

    function getEmail(): string {
        //TODO: Fix: return (string) array_slice($this->getMeta()->first()->json_content['emails'], 0, 1)[0];
        return '';
    }

    public function __toString()
    {
        //TODO: Fix: return $this->getEmail();
        return '';
    }

    public function getRoles(): array
    {
        // TODO: Implement getRoles() method.
        return array_merge($this->roles, ['ROLE_USER']);
    }

    public function getPassword(): null|string
    {
        //TODO: Fix: return $this->getMeta()->first()->getContent()->password;
        return null;
    }

    public function getSalt()
    {
        return '';
    }

    public function getUsername()
    {
        //TODO: Fix: return $this->getMeta()->first()->getContent()->username;
    }

    public function eraseCredentials(): void
    {
        // TODO: Implement eraseCredentials() method.
    }

    public function serialize() {

    }

    public function unserialize() {
        
    }

    public function __serialize(): array
    {
        // TODO: Implement serialize() method.
        return [];
    }

    public function __unserialize(array $data): void
    {
        // TODO: Implement unserialize() method.
    }

    public function registeredToSite(string $siteId) {
        //TODO: Fix: return isset($this->getMeta()->get(0)->json_content['roles'][$siteId]);
    }

    public function getSiteRoles(string $siteId) {
        //TODO: Fix: return $this->getMeta()->get(0)->json_content['roles'][$siteId] ?? [];
    }
}
