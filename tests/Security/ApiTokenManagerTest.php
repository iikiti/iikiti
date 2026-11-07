<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Security\ApiTokenManager;
use PHPUnit\Framework\TestCase;

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
}
