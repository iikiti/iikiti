<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\CheckRefreshedUserEvent;

/**
 * Injects the current site into a User entity after it has been loaded, and
 * preserves valid site-scoped sessions when refreshing on site-independent routes.
 */
#[AsDoctrineListener(event: Events::postLoad, priority: 500)]
class UserSiteContextSubscriber
{
    public function __construct(private readonly SiteRegistry $siteRegistry)
    {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof User) {
            return;
        }

        if (SiteRegistry::hasCurrent()) {
            $entity->setCurrentSite($this->siteRegistry::getCurrent());
        }
    }

    /**
     * Prevents false-positive deauthentication when a user logged in on a site
     * is refreshed on a site-independent route (where User::getRoles() only
     * returns default and global roles because no current site is loaded).
     */
    #[AsEventListener(event: CheckRefreshedUserEvent::class)]
    public function onCheckRefreshedUser(CheckRefreshedUserEvent $event): void
    {
        if (!$event->isUserChanged()) {
            return;
        }

        $originalUser = $event->getOriginalUser();
        $refreshedUser = $event->getRefreshedUser();
        if (!$originalUser instanceof User || !$refreshedUser instanceof User) {
            return;
        }

        if (null !== $refreshedUser->getCurrentSite()) {
            return;
        }

        $originalSite = $originalUser->getCurrentSite();
        $originalSiteId = $originalSite instanceof Site ? $originalSite->getId() : null;
        if (null === $originalSiteId) {
            return;
        }

        if ($originalUser->getUserIdentifier() !== $refreshedUser->getUserIdentifier()) {
            return;
        }

        $originalPassword = $originalUser->getPassword();
        $refreshedPassword = $refreshedUser->getPassword();
        if (null !== $originalPassword
            && $refreshedPassword !== $originalPassword
            && (8 !== strlen($originalPassword) || hash('crc32c', $refreshedPassword ?? '') !== $originalPassword)
        ) {
            return;
        }

        $expectedRoles = array_values(UserRoleManager::convertEnumsToStrings(array_merge(
            UserRoleManager::getDefaultRoles(),
            $refreshedUser->getRegistrationRoles($originalSiteId),
        )));
        $tokenRoleNames = $event->getToken()->getRoleNames();
        if (count($expectedRoles) !== count($tokenRoleNames)
            || count($expectedRoles) !== count(array_intersect($expectedRoles, $tokenRoleNames))
        ) {
            return;
        }

        $event->setUserChanged(false);
    }
}