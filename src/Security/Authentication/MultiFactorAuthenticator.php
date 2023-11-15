<?php

namespace iikiti\CMS\Security\Authentication;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class MultiFactorAuthenticator extends AbstractAuthenticator
{
	public function __construct()
	{
	}

	public function supports(Request $request): ?bool
	{
		return false;
	}

	public function authenticate(Request $request): Passport
	{
		$firewallMap = $this->container->get('security.firewall.map');
		dump($firewallMap);

		return new SelfValidatingPassport(
			new UserBadge($this->tokenStorage->getToken()?->getUser()->getUserIdentifier())
		);
	}

	public function onAuthenticationSuccess(
		Request $request,
		TokenInterface $token,
		string $firewallName
	): ?Response {
		return null;
	}

	public function onAuthenticationFailure(
		Request $request,
		AuthenticationException $exception
	): ?Response {
		return null;
	}
}
