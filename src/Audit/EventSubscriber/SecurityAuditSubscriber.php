<?php

namespace iikiti\CMS\Audit\EventSubscriber;

use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Records interactive login and logout as top-level audit events.
 *
 * Each opens its own named event, so any ApiToken creation that happens during
 * login is attached to it as a sub-event rather than appearing as a stray row.
 * The request-level listener sees these events already open and leaves them be.
 */
class SecurityAuditSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly AuditRecorder $recorder,
		private readonly RequestStack $requestStack,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			LoginSuccessEvent::class => ['onLoginSuccess', 0],
			LogoutEvent::class => ['onLogout', 0],
		];
	}

	public function onLoginSuccess(LoginSuccessEvent $event): void
	{
		// Only the interactive form login is audited; API token auth is stateless.
		if ('main' !== $event->getFirewallName()) {
			return;
		}

		$user = $event->getUser();
		if (!$user instanceof User) {
			return;
		}

		$ip = $this->requestStack->getCurrentRequest()?->getClientIp();
		$summary = sprintf('%s logged in%s', $user->getUserIdentifier(), null !== $ip ? sprintf(' from %s', $ip) : '');

		$this->recorder->openEvent('logged_in', $summary, ['userId' => $user->getId()]);
		$this->recorder->closeEvent();
	}

	public function onLogout(LogoutEvent $event): void
	{
		$user = $event->getToken()?->getUser();
		$username = $user instanceof User ? $user->getUserIdentifier() : 'Unknown user';

		$summary = sprintf('%s logged out', $username);

		$this->recorder->openEvent('logged_out', $summary, ['userId' => $user instanceof User ? $user->getId() : null]);
		$this->recorder->closeEvent();
	}
}
