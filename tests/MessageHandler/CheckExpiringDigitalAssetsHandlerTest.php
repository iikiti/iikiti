<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\MessageHandler\CheckExpiringDigitalAssetsHandler;
use iikiti\CMS\Repository\DigitalAssetRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CheckExpiringDigitalAssetsHandlerTest extends TestCase
{
	public function testCheckNotifiesExpiringSoonAndEnforcesMakePrivateOnExpired(): void
	{
		$now = new \DateTimeImmutable('2026-07-01T10:00:00Z');

		$expiringAsset = new UploadedDigitalAsset('Expiring Stock', 'image/jpeg', str_repeat('a', 64));
		$this->assignId($expiringAsset, 10);
		$expiringAsset->setVisibility('public');
		$expiringAsset->setExpiresAt(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
		$expiringAsset->setExpiryAction('alert');

		$expiredMakePrivate = new UploadedDigitalAsset('Expired Campaign', 'image/png', str_repeat('b', 64));
		$this->assignId($expiredMakePrivate, 20);
		$expiredMakePrivate->setVisibility('public');
		$expiredMakePrivate->setExpiresAt(new \DateTimeImmutable('2026-06-30T00:00:00Z'));
		$expiredMakePrivate->setExpiryAction('make_private');

		$expiredAlertOnly = new UploadedDigitalAsset('Expired Editorial', 'image/png', str_repeat('c', 64));
		$this->assignId($expiredAlertOnly, 30);
		$expiredAlertOnly->setVisibility('public');
		$expiredAlertOnly->setExpiresAt(new \DateTimeImmutable('2026-06-29T00:00:00Z'));
		$expiredAlertOnly->setExpiryAction('alert');

		$repo = $this->createStub(DigitalAssetRepository::class);
		$repo->method('findExpiringSoon')->willReturn([$expiringAsset]);
		$repo->method('findExpiredUnhandled')->willReturn([$expiredMakePrivate, $expiredAlertOnly]);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::once())->method('flush');

		$dispatchedExpiredIds = [];
		$dispatcher = $this->createMock(EventDispatcherInterface::class);
		$dispatcher->expects(self::exactly(2))->
			method('dispatch')->
			willReturnCallback(static function (object $event, ?string $name) use (&$dispatchedExpiredIds): object {
				self::assertInstanceOf(DigitalAssetEvent::class, $event);
				self::assertSame(DigitalAssetEvents::EXPIRED, $name);
				$dispatchedExpiredIds[] = $event->getAsset()->getId();

				return $event;
			});

		$notifier = $this->createMock(NotifierInterface::class);
		$notifier->expects(self::exactly(3))->method('send')->with(self::isInstanceOf(Notification::class));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(3))->method('warning');

		$handler = new CheckExpiringDigitalAssetsHandler(
			entityManager: $em,
			assets: $repo,
			warningDays: 14,
			eventDispatcher: $dispatcher,
			renderedFragmentCache: null,
			auditRecorder: null,
			notifier: $notifier,
			logger: $logger,
		);

		$summary = $handler->check($now);

		self::assertSame(['notified' => 1, 'expired' => 2, 'madePrivate' => 1], $summary);
		self::assertSame($now, $expiringAsset->getExpiryNotifiedAt());
		self::assertNull($expiringAsset->getExpiredHandledAt());
		self::assertSame('public', $expiringAsset->getVisibility());

		self::assertSame($now, $expiredMakePrivate->getExpiredHandledAt());
		self::assertSame('private', $expiredMakePrivate->getVisibility());

		self::assertSame($now, $expiredAlertOnly->getExpiredHandledAt());
		self::assertSame('public', $expiredAlertOnly->getVisibility());

		self::assertSame([20, 30], $dispatchedExpiredIds);
	}

	public function testCheckDoesNotFlushWhenNothingIsDue(): void
	{
		$repo = $this->createStub(DigitalAssetRepository::class);
		$repo->method('findExpiringSoon')->willReturn([]);
		$repo->method('findExpiredUnhandled')->willReturn([]);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::never())->method('flush');

		$handler = new CheckExpiringDigitalAssetsHandler($em, $repo);
		$summary = $handler->check(new \DateTimeImmutable('2026-07-01T10:00:00Z'));

		self::assertSame(['notified' => 0, 'expired' => 0, 'madePrivate' => 0], $summary);
	}

	private function assignId(DbObject $object, int $id): void
	{
		$ref = new \ReflectionProperty(DbObject::class, 'id');
		$ref->setValue($object, $id);
	}
}
