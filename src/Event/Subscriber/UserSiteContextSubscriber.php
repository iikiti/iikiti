<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use Override;

/**
 * Injects the current site into a User entity after it has been loaded.
 */
#[AsDoctrineListener(event: Events::postLoad, priority: 500)]
class UserSiteContextSubscriber
{
    public function __construct(private readonly SiteRegistry $siteRegistry)
    {
    }

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
}