<?php
namespace iikiti\CMS\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

class MultiFactorAuthenticationTokenListener implements EventSubscriberInterface {

	public static function getSubscribedEvents() {
		return [
			AuthenticationTokenCreatedEvent::class => 'onGeneralTokenGenerated'
		];
	}

	public static function onGeneralTokenGenerated(AuthenticationTokenCreatedEvent $event): void {
		//dump($event);
	}

}

