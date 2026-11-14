<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller;

use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeCookie;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeVerifier;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accepts a browser's proof-of-work solution and issues the clearance cookie.
 *
 * Responses are never cached. Failures return a generic 403 so the endpoint does not
 * reveal which check rejected a solution.
 */
final class FirewallChallengeController
{
	public function __construct(
		private readonly FirewallChallengeVerifier $verifier,
		private readonly FirewallChallengeCookie $cookies,
	) {
	}

	#[Route('/_firewall/challenge/verify', name: 'firewall_challenge_verify', methods: ['POST'])]
	public function __invoke(Request $request): Response
	{
		$challenge = $request->request->get('challenge');
		$solution = $request->request->get('solution');
		if (!is_string($challenge) || !is_string($solution)) {
			return $this->rejected();
		}

		$payload = $this->verifier->verify($request, $challenge, $solution);
		if (null === $payload) {
			return $this->rejected();
		}

		$response = new RedirectResponse($this->verifier->returnPath($payload), Response::HTTP_SEE_OTHER);
		$response->headers->set('Cache-Control', 'no-store, private');
		$response->headers->setCookie($this->cookies->issue($request));

		return $response;
	}

	private function rejected(): Response
	{
		return new Response('Verification failed. Reload the page to try again.', Response::HTTP_FORBIDDEN, [
			'Content-Type' => 'text/plain; charset=UTF-8',
			'Cache-Control' => 'no-store, private',
		]);
	}
}
