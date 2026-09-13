<?php

namespace iikiti\CMS\Security;

use iikiti\CMS\Authentication\Token\AuthenticationToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Redirects users who have not completed their multi-factor challenge.
 *
 * Whenever access is denied to a token that is still waiting on a challenge,
 * the user is sent to the challenge form instead of the error page. The
 * requested page is remembered so the user is returned there afterwards.
 */
class AccessHandler implements AccessDeniedHandlerInterface
{
	public const MFA_ROUTE = 'mfa_challenge';

	private const FLASH_MESSAGE = 'Please complete multi-factor authentication.';

	public function __construct(
		private readonly Security $security,
		private readonly RouterInterface $router,
		private readonly RequestStack $requestStack,
		private readonly TargetPathStorage $targetPathStorage,
	) {
	}

	public function handle(
		Request $request,
		AccessDeniedException $accessDeniedException,
	): ?Response {
		$token = $this->security->getToken();
		if (!$token instanceof AuthenticationToken || $token->isAuthenticated()) {
			return null;
		}

		if ($this->isAlreadyOnChallenge($request)) {
			return null;
		}

		$this->targetPathStorage->store($request->getRequestUri());
		$this->addFlashMessage();

		return new RedirectResponse($this->router->generate(self::MFA_ROUTE));
	}

	private function isAlreadyOnChallenge(Request $request): bool
	{
		try {
			$route = $this->router->match($request->getPathInfo())['_route'] ?? null;
		} catch (ResourceNotFoundException) {
			return false;
		}

		return self::MFA_ROUTE === $route;
	}

	private function addFlashMessage(): void
	{
		try {
			$session = $this->requestStack->getSession();
		} catch (SessionNotFoundException) {
			return;
		}

		if ($session instanceof FlashBagAwareSessionInterface) {
			$session->getFlashBag()->add('error', self::FLASH_MESSAGE);
		}
	}
}
