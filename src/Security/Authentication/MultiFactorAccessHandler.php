<?php

namespace iikiti\CMS\Security\Authentication;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class MultiFactorAccessHandler implements AccessDeniedHandlerInterface
{
	public function __construct(
		private Security $security,
		private RouterInterface $router
	) {
	}

	public function handle(
		Request $request,
		AccessDeniedException $accessDeniedException
	): ?Response {
		$token = $this->security->getToken();
		if (
			$token instanceof MultiFactorAuthenticationToken &&
			false === $token->isAuthenticated() &&
			'html_login' != $this->router->match($request->getPathInfo())['_route']
		) {
			$session = $request->getSession();
			$message = 'Please complete multi-factor authentication.';
			if (
				false == $session->isStarted() ||
				false == ($session instanceof FlashBagAwareSessionInterface)
			) {
				if (false == ($accessDeniedException instanceof MultiFactorAccessDeniedException)) {
					throw new MultiFactorAccessDeniedException($message, $accessDeniedException);
				}
			} else {
				$session->getFlashBag()->set('error', $message);
			}

			return new RedirectResponse($this->router->generate('html_login'));
		}

		return null;
	}
}
