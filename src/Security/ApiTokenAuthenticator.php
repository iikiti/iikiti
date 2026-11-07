<?php

namespace iikiti\CMS\Security;

use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Entity\Object\ApiToken;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
	public function __construct(
		private ApiTokenRepository $apiTokenRepository,
		private GuestApiTokenManager $guestTokens,
	)
	{
	}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-AUTH-TOKEN');
    }

    public function authenticate(Request $request): Passport
    {
        $apiToken = $request->headers->get('X-AUTH-TOKEN');
		if (null === $apiToken) {
			throw new CustomUserMessageAuthenticationException('No API token provided');
		}
		if (str_starts_with($apiToken, 'guest1.')) {
			$guest = $this->guestTokens->decode($apiToken);
			if (null === $guest
				|| !in_array($request->getMethod(), ['GET', 'HEAD'], true)
				|| (!str_starts_with($request->getPathInfo(), '/api/public/render/')
					&& !str_starts_with($request->getPathInfo(), '/api/public/assets/'))) {
				throw new CustomUserMessageAuthenticationException('Invalid or out-of-scope guest API credential');
			}
			$siteHost = $request->headers->get('X-SITE-HOST');
			if (null === $siteHost || 0 !== strcasecmp($guest->getSiteHost(), $siteHost)) {
				throw new CustomUserMessageAuthenticationException('Guest API credential is scoped to another site');
			}

			return new SelfValidatingPassport(new UserBadge(
				$guest->getUserIdentifier(),
				static fn (): GuestApiUser => $guest,
			));
		}
		if (!str_starts_with($apiToken, ApiToken::TOKEN_PREFIX)) {
			throw new CustomUserMessageAuthenticationException('Invalid API token version');
		}

		$token = $this->apiTokenRepository->findOneBy(['token' => $apiToken]);
        if (null === $token || $token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Invalid API token');
        }

        return new SelfValidatingPassport(new UserBadge($token->getUser()->getUserIdentifier()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = [
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
}
