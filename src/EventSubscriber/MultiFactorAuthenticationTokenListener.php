<?php
namespace iikiti\CMS\EventSubscriber;

use iikiti\CMS\Security\Authentication\MultiFactorAuthenticationToken;
use iikiti\CMS\Security\Authentication\MultiFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

class MultiFactorAuthenticationTokenListener implements EventSubscriberInterface
{

	public static function getSubscribedEvents(): array
	{
		return [
				//CheckPassportEvent::class => 'onCheckPassport',
			AuthenticationTokenCreatedEvent::class => 'onGeneralTokenCreated'
		];
	}

	public static function onGeneralTokenCreated(AuthenticationTokenCreatedEvent $event): void
	{
		$token = $event->getAuthenticatedToken();

		if ($token instanceof MultiFactorTokenInterface) {
			return;
		}

		$mfToken = new MultiFactorAuthenticationToken();
		$mfToken->setAssociatedToken($token);

		$event->setAuthenticatedToken($mfToken);
	}

}

