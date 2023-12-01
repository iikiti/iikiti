<?php

namespace iikiti\CMS\Security\Authentication;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class MultiFactorAuthenticator extends AbstractAuthenticator
{
	public function __construct(
		private Security $s,
		private EntityManagerInterface $em,
		private UserProviderInterface $userProvider,
		private ParameterBagInterface $parameterBag,
		private array $options = []
	) {
	}

	public function supports(Request $request): ?bool
	{
		// TODO: Check for form submission
		if (
			$request->hasSession() &&
			$request->getSession()->has(SecurityRequestAttributes::LAST_USERNAME)
		) {
			return true;
		}

		return false;
	}

	public function authenticate(Request $request): Passport
	{
		$username = $request->getSession()->get(SecurityRequestAttributes::LAST_USERNAME);
		$token = ''; // TODO: Acquire token

		$passport = new Passport(
			new UserBadge($username),
			new CustomCredentials(
				function (string $token, UserInterface $user): bool {
					// TODO: Check token
					return false;
				},
				$token
			),
			[new RememberMeBadge()]
		);

		if ($this->parameterBag->get('form.type_extension.csrf.enabled') ?? false) {
			$passport->addBadge(
				new CsrfTokenBadge(
					'mfa',
					(string) $request->request->get('_csrf_token', null)
				)
			);
		}

		return $passport;
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
