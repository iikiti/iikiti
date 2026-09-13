<?php

namespace iikiti\CMS\Authentication\Event\Subscriber;

use iikiti\CMS\Authentication\MfaPreferenceResolver;
use iikiti\CMS\Authentication\Token\AuthenticationToken;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Places a multi-factor proxy token in front of the login token.
 *
 * When the authenticated user must complete a multi-factor challenge, the
 * real token is wrapped in an {@see AuthenticationToken} that reports itself
 * as unauthenticated until the challenge succeeds.
 */
class AuthenticationTokenSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly MfaPreferenceResolver $preferenceResolver,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			AuthenticationTokenCreatedEvent::class => 'onAuthenticationTokenCreated',
		];
	}

	public function onAuthenticationTokenCreated(AuthenticationTokenCreatedEvent $event): void
	{
		// Credential-less authentication (for example the stateless API token
		// authenticator) is not an interactive login and must keep its token.
		if ($event->getPassport() instanceof SelfValidatingPassport) {
			return;
		}

		$token = $event->getAuthenticatedToken();
		$user = $token->getUser();

		if (null === $user || $token instanceof AuthenticationToken) {
			return;
		}

		$configuration = $this->preferenceResolver->resolve($user);
		if (false === $configuration->isEnabled() || [] === $configuration->getEnabledMethods()) {
			return;
		}

		$mfaToken = new AuthenticationToken($token->getRoleNames());
		$mfaToken->setAssociatedToken($token);

		$event->setAuthenticatedToken($mfaToken);
	}
}
