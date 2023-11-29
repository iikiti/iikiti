<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;

#[AsEntityListener(event: Events::postLoad, method: 'onPostLoad', entity: User::class)]
class UserLoadListener
{
	public function __construct(protected SiteRegistry $siteRegistry)
	{
	}

	public function onPostLoad(User $user, PostLoadEventArgs $eventArgs): void
	{
		$user->setCurrentSiteId($this->siteRegistry->getCurrentSite()->getId());
	}
}
