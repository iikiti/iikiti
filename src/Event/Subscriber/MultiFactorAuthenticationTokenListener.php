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

		// TODO: Check user for multi-factor login requirement.
		$user = $token->getUser();

		if (
			$token instanceof MultiFactorTokenInterface
			// TODO: OR User does not require MFA
		) {
			return;
		}

		$mfaToken = new MultiFactorAuthenticationToken();
		$mfaToken->setAssociatedToken($token);

		$event->setAuthenticatedToken($mfaToken);
	}
}
