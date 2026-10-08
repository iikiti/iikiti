<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Audit;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditContext;
use iikiti\CMS\Audit\AuditLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tests that AuditLogger respects the $flush flag, preventing infinite
 * recursion when called from the onFlush event handler.
 */
final class AuditLoggerTest extends TestCase
{
	private EntityManagerInterface&MockObject $entityManager;
	private TokenStorageInterface $tokenStorage;
	private RequestStack $requestStack;
	private \Psr\Log\LoggerInterface $logger;

	protected function setUp(): void
	{
		$this->entityManager = $this->createMock(EntityManagerInterface::class);
		$this->tokenStorage = $this->createStub(TokenStorageInterface::class);
		$this->tokenStorage->method('getToken')->willReturn(null);
		$this->requestStack = new RequestStack();
		$this->logger = $this->createStub(\Psr\Log\LoggerInterface::class);
	}

	public function testLogWithoutFlushDoesNotCallFlush(): void
	{
		$this->entityManager->expects(self::once())->method('persist');
		$this->entityManager->expects(self::never())->method('flush');

		$logger = new AuditLogger(
			$this->entityManager,
			$this->tokenStorage,
			$this->requestStack,
			new AuditContext(),
			$this->logger,
			'dev',
		);

		$logger->log('created', 'ApiToken', null, null, null, 'user', [], false);
	}

	public function testLogWithFlushCallsFlush(): void
	{
		$this->entityManager->expects(self::once())->method('persist');
		$this->entityManager->expects(self::once())->method('flush');

		$logger = new AuditLogger(
			$this->entityManager,
			$this->tokenStorage,
			$this->requestStack,
			new AuditContext(),
			$this->logger,
			'dev',
		);

		$logger->log('created', 'ApiToken', null, null, null, 'user', [], true);
	}

	public function testLogDefaultsToFlush(): void
	{
		$this->entityManager->expects(self::once())->method('persist');
		$this->entityManager->expects(self::once())->method('flush');

		$logger = new AuditLogger(
			$this->entityManager,
			$this->tokenStorage,
			$this->requestStack,
			new AuditContext(),
			$this->logger,
			'dev',
		);

		// No $flush argument — should default to true.
		$logger->log('created', 'ApiToken');
	}

	public function testLogWithoutFlushStillPersistsEntry(): void
	{
		$this->entityManager->expects(self::once())->method('persist')
			->with(self::callback(function (object $entry): bool {
				return $entry::class === \iikiti\CMS\Entity\AuditLogEntry::class;
			}));
		$this->entityManager->expects(self::never())->method('flush');

		$logger = new AuditLogger(
			$this->entityManager,
			$this->tokenStorage,
			$this->requestStack,
			new AuditContext(),
			$this->logger,
			'test',
		);

		$logger->log('created', 'ApiToken', null, null, null, 'user', [], false);
	}
}
