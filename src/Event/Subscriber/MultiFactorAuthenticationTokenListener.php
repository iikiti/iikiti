<?php

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Security\Authentication\MultiFactorAuthenticationToken;
use iikiti\CMS\Security\Authentication\MultiFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

class MultiFactorAuthenticationTokenListener implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			AuthenticationTokenCreatedEvent::class => 'onGeneralTokenCreated',
		];
	}

	public static function onGeneralTokenCreated(AuthenticationTokenCreatedEvent $event): void
	{
		$token = $event->getAuthenticatedToken();

		if ($token instanceof MultiFactorTokenInterface) {
			return;
		}

		$mfaToken = new MultiFactorAuthenticationToken();
		$mfaToken->setAssociatedToken($token);

		$event->setAuthenticatedToken($mfaToken);
	}
}
