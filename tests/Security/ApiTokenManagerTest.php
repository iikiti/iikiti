<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Security\ApiTokenLogoutSubscriber;
use iikiti\CMS\Security\ApiTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ApiTokenManagerTest extends TestCase
{
	public function testRotatesLegacyTokenToCurrentVersion(): void
	{
		$user = $this->createStub(User::class);
		$user->method('getId')->willReturn(12);
		$user->method('getUserIdentifier')->willReturn('editor');
		$legacy = new ApiToken();
		$legacy->setToken(bin2hex(random_bytes(32)));
		$legacy->setUser($user);
		$legacy->setExpiresAt((new \DateTimeImmutable())->modify('+30 minutes'));
		$repository = $this->createMock(ApiTokenRepository::class);
		$repository->expects(self::once())->method('findOneBy')->with(['user' => $user])->willReturn($legacy);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('getRepository')->with(ApiToken::class)->willReturn($repository);
		$entityManager->expects(self::once())->method('remove')->with($legacy);
		$entityManager->expects(self::once())->method('persist')->with(self::callback(static function (ApiToken $token): bool {
			return $token->isCurrentVersion();
		}));
		$entityManager->expects(self::exactly(2))->method('flush');
		$recorder = $this->createMock(AuditRecorder::class);
		$recorder->expects(self::exactly(2))->method('record');

		$token = (new ApiTokenManager($entityManager, $recorder))->getOrCreateToken($user);

		self::assertTrue($token->isCurrentVersion());
		self::assertSame(ApiToken::TOKEN_PREFIX, substr($token->getToken(), 0, strlen(ApiToken::TOKEN_PREFIX)));
	}

	public function testReusesAnUnexpiredCurrentVersionToken(): void
	{
		$user = $this->createStub(User::class);
		$token = new ApiToken();
		$token->setToken(ApiToken::TOKEN_PREFIX.bin2hex(random_bytes(32)));
		$token->setUser($user);
		$token->setExpiresAt((new \DateTimeImmutable())->modify('+30 minutes'));
		$repository = $this->createMock(ApiTokenRepository::class);
		$repository->expects(self::once())->method('findOneBy')->with(['user' => $user])->willReturn($token);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('getRepository')->with(ApiToken::class)->willReturn($repository);
		$entityManager->expects(self::never())->method('remove');
		$entityManager->expects(self::never())->method('persist');
		$recorder = $this->createMock(AuditRecorder::class);
		$recorder->expects(self::never())->method('record');

		self::assertSame($token, (new ApiTokenManager($entityManager, $recorder))->getOrCreateToken($user));
	}

	public function testRevokesAllUserTokensWithoutReadingCacheAndLogsCapturedId(): void
	{
		$user = $this->createStub(User::class);
		$user->method('getId')->willReturn(42);
		$user->method('getUserIdentifier')->willReturn('alice');
		$token = new ApiToken();
		(new \ReflectionProperty(\iikiti\CMS\Entity\DbObject::class, 'id'))->setValue($token, 99);
		$repository = $this->createMock(ApiTokenRepository::class);
		$repository->expects(self::once())
			->method('findBy')
			->with(['user' => $user], null, null, null, ['cache' => false])
			->willReturn([$token]);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('getRepository')->with(ApiToken::class)->willReturn($repository);
		$entityManager->expects(self::once())->method('remove')->with($token);
		$entityManager->expects(self::once())->method('flush')->willReturnCallback(static function () use ($token): void {
			// Simulate Doctrine clearing the entity ID after flush().
			(new \ReflectionProperty(\iikiti\CMS\Entity\DbObject::class, 'id'))->setValue($token, null);
		});
		$recorder = $this->createMock(AuditRecorder::class);
		$recorder->expects(self::once())
			->method('record')
			->with(self::anything(), 'deleted_api_token', 'ApiToken', 99, null, null, ['userId' => 42]);

		(new ApiTokenManager($entityManager, $recorder))->revokeForUser($user);
	}

	public function testLogoutSubscriberClearsSessionCacheAndRevokesDbTokensOutsideConsumerRole(): void
	{
		$user = $this->createStub(User::class);
		$token = $this->createStub(TokenInterface::class);
		$token->method('getUser')->willReturn($user);
		$session = $this->createMock(SessionInterface::class);
		$session->method('isStarted')->willReturn(true);
		$session->expects(self::exactly(2))->method('remove')->with(ApiSessionTokenProvider::SESSION_CACHE_KEY);
		$request = Request::create('https://example.test/api/auth/logout');
		$request->setSession($session);

		$apiManager = $this->createMock(ApiTokenManager::class);
		$apiManager->expects(self::once())->method('revokeForUser')->with($user);
		(new ApiTokenLogoutSubscriber($apiManager, 'api'))->onLogout(new LogoutEvent($request, $token));

		$consumerManager = $this->createMock(ApiTokenManager::class);
		$consumerManager->expects(self::never())->method('revokeForUser');
		(new ApiTokenLogoutSubscriber($consumerManager, 'consumer'))->onLogout(new LogoutEvent($request, $token));
	}

	public function testLogoutSubscriberDoesNotStartUnstartedSessionOnCookielessLogout(): void
	{
		$session = $this->createMock(SessionInterface::class);
		$session->method('isStarted')->willReturn(false);
		$session->expects(self::never())->method('remove');
		$request = Request::create('https://example.test/api/auth/logout');
		$request->setSession($session);

		$manager = $this->createMock(ApiTokenManager::class);
		$manager->expects(self::never())->method('revokeForUser');
		(new ApiTokenLogoutSubscriber($manager, 'api'))->onLogout(new LogoutEvent($request, null));
	}
}
