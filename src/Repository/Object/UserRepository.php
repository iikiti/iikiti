<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 *
 */
class UserRepository extends ObjectRepository implements UserLoaderInterface {

    public function __construct(ManagerRegistry $registry, string $entityClass = User::class) {
        parent::__construct($registry, $entityClass);
    }

    public function loadUserByIdentifier(string $identifier): ?User {
        return $this->loadUserByUsername($identifier);
    }

    /**
     * Loads the user for the given username.
     *
     * This method must return null if the user is not found.
     *
     * @return User|null
     */
    public function loadUserByUsername(string $username): ?User {
		$users = $this->findByProperty(
			'username',
			new Comparison('username', Comparison::EQ, $username)
		);
        /*
         * Find site user link (if exists).
         * E.g. The found user is a user of the current site.
         */
        $registeredToSite = $user->registeredToSite(
			SiteRegistry::getCurrentSite()->getId()
		);
        return $registeredToSite ? $user : null;
    }
}
