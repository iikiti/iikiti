<?php
namespace iikiti\CMS\EventSubscriber;

use iikiti\CMS\Security\Authentication\MultiFactorAuthenticationToken;
use iikiti\CMS\Security\Authentication\MultiFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

class MultiFactorAuthenticationTokenListener implements EventSubscriberInterface {

	public static function getSubscribedEvents(): array {
		return [
			//CheckPassportEvent::class => 'onCheckPassport',
			AuthenticationTokenCreatedEvent::class => 'onGeneralTokenCreated'
		];
	}

	public static function onCheckPassport(CheckPassportEvent $event): ?Passport {
		dump($event);
		return $event->getPassport();
	}

	public static function onGeneralTokenCreated(AuthenticationTokenCreatedEvent $event): void {
		$token = $event->getAuthenticatedToken();

		if($token instanceof MultiFactorTokenInterface) {
			return;
		}

		$mfToken = new MultiFactorAuthenticationToken();
		$mfToken->setAssociatedToken($token);

		$event->setAuthenticatedToken($mfToken);
	}

}

