<?php

declare(strict_types=1);

namespace iikiti\CMS\Security;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Revokes ephemeral user API tokens in the database and clears any cached
 * session-token entry when a user logs out.
 */
final readonly class ApiTokenLogoutSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private ApiTokenManager $apiTokenManager,
		#[Autowire('%application_role%')]
		private string $applicationRole = 'api',
	) {
	}

	public static function getSubscribedEvents(): array
	{
		// Run before Symfony's SessionLogoutHandler (priority 0) invalidates the session.
		return [
			LogoutEvent::class => ['onLogout', 10],
		];
	}

	public function onLogout(LogoutEvent $event): void
	{
		$request = $event->getRequest();
		if ($request->hasSession() && ($request->hasPreviousSession() || $request->getSession()->isStarted())) {
			$request->getSession()->remove(ApiSessionTokenProvider::SESSION_CACHE_KEY);
		}

		if ('consumer' === $this->applicationRole) {
			return;
		}

		$user = $event->getToken()?->getUser();
		if ($user instanceof User) {
			$this->apiTokenManager->revokeForUser($user);
		}
	}
}
