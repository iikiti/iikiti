<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api;

use iikiti\CMS\Controller\Api\SessionApiTokenController;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\MfaAuthenticationGate;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SessionApiTokenControllerTest extends TestCase
{
	public function testIssuesTheCurrentSessionUsersToken(): void
	{
		$user = $this->createStub(User::class);
		$token = $this->createStub(ApiToken::class);
		$token->method('getToken')->willReturn('user-scoped-token');
		$user->method('getUsername')->willReturn('editor');
		$user->method('getTimeZone')->willReturn(null);
		$tokenManager = $this->createMock(ApiTokenManager::class);
		$tokenManager->expects(self::once())->method('getOrCreateToken')->with($user)->willReturn($token);
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);

		$response = (new SessionApiTokenController($security, $tokenManager, $this->mfaGate(true)))->issue();

		self::assertSame(200, $response->getStatusCode());
		self::assertSame([
			'token' => 'user-scoped-token',
			'username' => 'editor',
			'timeZone' => '',
		], json_decode((string) $response->getContent(), true));
		self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
		self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
	}

	public function testDoesNotIssueTokenWithoutAnAuthenticatedUserSession(): void
	{
		$tokenManager = $this->createMock(ApiTokenManager::class);
		$tokenManager->expects(self::never())->method('getOrCreateToken');
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(null);

		$response = (new SessionApiTokenController($security, $tokenManager, $this->mfaGate(false)))->issue();

		self::assertSame(401, $response->getStatusCode());
	}

	public function testDoesNotIssueTokenFromAnMfaPendingUserProxy(): void
	{
		$user = $this->createStub(User::class);
		$tokenManager = $this->createMock(ApiTokenManager::class);
		$tokenManager->expects(self::never())->method('getOrCreateToken');
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);
		$controller = new SessionApiTokenController($security, $tokenManager, $this->mfaGate(true, true));

		self::assertSame(401, $controller->issue()->getStatusCode());
	}

	private function mfaGate(bool $fullyAuthenticated, bool $pending = false): MfaAuthenticationGate
	{
		$security = $this->createStub(Security::class);
		$security->method('isGranted')->willReturn($fullyAuthenticated);
		$storage = $this->createStub(TokenStorageInterface::class);
		if ($pending) {
			$mfaToken = new \iikiti\CMS\Authentication\Token\AuthenticationToken();
			$mfaToken->setAssociatedToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
				$this->createStub(User::class),
				'main',
				['ROLE_ADMIN'],
			));
			$storage->method('getToken')->willReturn($mfaToken);
		}

		return new MfaAuthenticationGate($security, $storage);
	}
}
