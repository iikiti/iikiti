<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 *
 */
class UserRepository extends ObjectRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry, string $entityClass = User::class)
    {
        parent::__construct($registry, $entityClass);
    }

    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->loadUserByUsername($identifier);
    }

    /**
     * Loads the user for the given username.
     *
     * This method must return null if the user is not found.
     *
     * @return UserInterface|null
     */
    public function loadUserByUsername(string $username): ?UserInterface
    {
        /* @var null|\iikiti\CMS\Entity\Object\User $user */
        $user = $this->findByMetaValue(
            'JSON_CONTAINS(' .
                'meta.json_content, ' .
                'JSON_QUOTE(:json_value), ' .
                '\'$.emails\'' .
            ')',
            1,
            true,
            [ 'json_value' => $username ]
        )->getSingleResult();
        if($user === null) {
            return null;
        }
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
