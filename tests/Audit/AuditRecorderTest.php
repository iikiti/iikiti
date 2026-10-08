<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Audit;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditContext;
use iikiti\CMS\Audit\AuditLogger;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\AuditLogEntry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class AuditRecorderTest extends TestCase
{
	private EntityManagerInterface&MockObject $entityManager;
	private AuditContext $context;
	private \Psr\Log\LoggerInterface $logger;
	private AuditRecorder $recorder;

	protected function setUp(): void
	{
		$this->entityManager = $this->createMock(EntityManagerInterface::class);
		$tokenStorage = $this->createStub(TokenStorageInterface::class);
		$tokenStorage->method('getToken')->willReturn(null);

		$this->logger = $this->createStub(\Psr\Log\LoggerInterface::class);
		$this->context = new AuditContext();
		$logger = new AuditLogger(
			$this->entityManager,
			$tokenStorage,
			new RequestStack(),
			$this->context,
			$this->logger,
			'test',
		);
		$this->recorder = new AuditRecorder($logger);
	}

	public function testRecordRejectsEmptySummary(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->recorder->record('   ', 'created', 'Page');
	}

	public function testRecordOutsideEventPersistsTopLevelEntryWithSummary(): void
	{
		$persisted = [];
		$this->entityManager->method('persist')->willReturnCallback(
			static function (object $entry) use (&$persisted): void {
				$persisted[] = $entry;
			},
		);

		$this->recorder->record('Created page "Home"', 'created', 'Page', 7);

		self::assertCount(1, $persisted);
		self::assertInstanceOf(AuditLogEntry::class, $persisted[0]);
		self::assertSame('Created page "Home"', $persisted[0]->getSummary());
		self::assertNull($persisted[0]->getParent());
	}

	public function testChangesInsideOpenEventBecomeSubEventsOfParent(): void
	{
		$this->recorder->openEvent('login', 'jimbo logged in');
		$this->recorder->record('Created API token', 'created_api_token', 'ApiToken', 3);
		$this->recorder->record('Deleted property "title"', 'property_deleted', 'ObjectProperty', 9);

		$subEvents = $this->context->takeSubEvents();
		self::assertCount(2, $subEvents);
		foreach ($subEvents as $sub) {
			self::assertSame($this->context->getParent(), $sub->getParent());
		}
	}

	public function testEventWithNoChangesPersistsNothing(): void
	{
		$this->entityManager->expects(self::never())->method('persist');

		$this->recorder->openEvent('request', 'GET /');
		$this->recorder->closeEvent();
	}

	public function testEventWithChangesPersistsParentAndSubEvents(): void
	{
		$persisted = [];
		$this->entityManager->method('persist')->willReturnCallback(
			static function (object $entry) use (&$persisted): void {
				$persisted[] = $entry;
			},
		);

		$this->recorder->openEvent('login', 'jimbo logged in');
		$this->recorder->record('Created API token', 'created_api_token', 'ApiToken', 3);
		$this->recorder->closeEvent();

		self::assertCount(2, $persisted);

		// Sub-events are persisted as they are recorded, the parent on close.
		$subEvent = array_values(array_filter($persisted, static fn (object $e): bool => null !== $e->getParent()))[0];
		$parent = $subEvent->getParent();
		self::assertSame('jimbo logged in', $parent->getSummary());
		self::assertNull($parent->getParent());
	}

	public function testRawLoggerCallWithoutSummaryFallsBackAndWarns(): void
	{
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with(self::stringContains('without a human-readable summary'), self::arrayHasKey('source'));

		$tokenStorage = $this->createStub(TokenStorageInterface::class);
		$tokenStorage->method('getToken')->willReturn(null);
		$auditLogger = new AuditLogger($this->entityManager, $tokenStorage, new RequestStack(), new AuditContext(), $logger, 'test');

		$this->entityManager->method('persist');
		$auditLogger->log('created', 'Page', 3, null, null, 'user', [], false);
	}

	public function testParentIsPersistedBeforeItsSubEventsOnClose(): void
	{
		$order = [];
		$this->entityManager->method('persist')->willReturnCallback(
			static function (object $entry) use (&$order): void {
				$order[] = $entry->getAction();
			},
		);

		$this->recorder->openEvent('logged_out', 'jimbo logged out');
		$this->recorder->record('Created API token', 'created_api_token', 'ApiToken', 3);
		$this->recorder->closeEvent();

		// The parent must be managed before its child is persisted, otherwise
		// Doctrine rejects the new relation at flush time.
		self::assertSame(['logged_out', 'created_api_token'], $order);
	}
}
