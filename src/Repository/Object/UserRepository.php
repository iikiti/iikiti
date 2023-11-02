<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\User;
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
        /*
         * Find site user link (if exists).
         * E.g. The found user is a user of the current site.
		 * TODO: Needs to be made into an authenticator
         */
		/*
        $registeredToSite = $user->registeredToSite(
			SiteRegistry::getCurrentSite()->getId()
		);
		*/
        return $this->findOneByProperty('username', $username);
    }
}
