<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Message\CheckExpiringDigitalAssets;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final readonly class CheckExpiringDigitalAssetsHandler
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private DigitalAssetRepository $assets,
		#[Autowire('%asset_expiry_warning_days%')]
		private int $warningDays = 14,
		private ?EventDispatcherInterface $eventDispatcher = null,
		private ?RenderedFragmentCache $renderedFragmentCache = null,
		private ?AuditRecorder $auditRecorder = null,
		private ?NotifierInterface $notifier = null,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function __invoke(CheckExpiringDigitalAssets $message): void
	{
		$this->check();
	}

	/**
	 * Scans for expiring-soon and newly expired assets relative to `$now`,
	 * sending alerts and enforcing `make_private` actions idempotently.
	 *
	 * @return array{notified: int, expired: int, madePrivate: int}
	 */
	public function check(?\DateTimeImmutable $now = null): array
	{
		$clock = $now ?? new \DateTimeImmutable();
		$warningWindowDays = max(1, $this->warningDays);
		$until = $clock->modify(sprintf('+%d days', $warningWindowDays));

		$notified = 0;
		$expired = 0;
		$madePrivate = 0;
		$dirty = false;
		$invalidateCache = false;
		$expiredEvents = [];

		foreach ($this->assets->findExpiringSoon($clock, $until) as $asset) {
			$asset->setExpiryNotifiedAt($clock);
			$dirty = true;
			++$notified;

			$assetId = $asset->getId();
			$expiresIso = $asset->getExpiresAt()?->format(\DateTimeInterface::ATOM);

			$this->auditRecorder?->record(
				sprintf('Digital asset #%s ("%s") license expires soon (%s)', (string) ($assetId ?? ''), $asset->getTitle(), $expiresIso ?? ''),
				'updated',
				'digital_asset_license',
				$assetId,
				null,
				null,
				[
					'event' => 'expiring_soon',
					'expiresAt' => $expiresIso,
					'expiryAction' => $asset->getExpiryAction(),
					'warningDays' => $warningWindowDays,
				],
			);

			$this->logger?->warning('Digital asset license expiring soon.', [
				'assetId' => $assetId,
				'title' => $asset->getTitle(),
				'expiresAt' => $expiresIso,
				'expiryAction' => $asset->getExpiryAction(),
			]);

			$this->sendNotification(
				sprintf('Digital asset license expiring soon: %s', $asset->getTitle()),
				sprintf(
					'Asset #%s ("%s") license expires at %s (configured action: %s).',
					(string) ($assetId ?? ''),
					$asset->getTitle(),
					$expiresIso ?? 'unknown',
					$asset->getExpiryAction() ?? AbstractDigitalAsset::EXPIRY_ACTION_ALERT,
				),
				Notification::IMPORTANCE_MEDIUM,
			);
		}

		foreach ($this->assets->findExpiredUnhandled($clock) as $asset) {
			$asset->setExpiredHandledAt($clock);
			$dirty = true;
			++$expired;

			$action = $asset->getExpiryAction();
			$previousVisibility = $asset->getVisibility();
			$visibilityChanged = false;

			if (AbstractDigitalAsset::EXPIRY_ACTION_MAKE_PRIVATE === $action && 'private' !== $previousVisibility) {
				$asset->setVisibility('private');
				$visibilityChanged = true;
				$invalidateCache = true;
				++$madePrivate;
			}

			$assetId = $asset->getId();
			$expiresIso = $asset->getExpiresAt()?->format(\DateTimeInterface::ATOM);

			$this->auditRecorder?->record(
				sprintf(
					'Digital asset #%s ("%s") license expired%s',
					(string) ($assetId ?? ''),
					$asset->getTitle(),
					$visibilityChanged ? '; visibility changed to private' : '',
				),
				'updated',
				'digital_asset_license',
				$assetId,
				['visibility' => $previousVisibility],
				['visibility' => $asset->getVisibility()],
				[
					'event' => 'expired',
					'expiresAt' => $expiresIso,
					'expiryAction' => $action,
					'madePrivate' => $visibilityChanged,
				],
			);

			$this->logger?->warning('Digital asset license expired.', [
				'assetId' => $assetId,
				'title' => $asset->getTitle(),
				'expiresAt' => $expiresIso,
				'expiryAction' => $action,
				'madePrivate' => $visibilityChanged,
			]);

			$this->sendNotification(
				sprintf('Digital asset license expired: %s', $asset->getTitle()),
				sprintf(
					'Asset #%s ("%s") license expired at %s.%s',
					(string) ($assetId ?? ''),
					$asset->getTitle(),
					$expiresIso ?? 'unknown',
					$visibilityChanged ? ' Visibility has been switched to private.' : '',
				),
				Notification::IMPORTANCE_HIGH,
			);

			$expiredEvents[] = $asset;
		}

		if ($dirty) {
			$this->entityManager->flush();
		}

		if ($invalidateCache) {
			$this->renderedFragmentCache?->invalidateCmsDependencies();
		}

		foreach ($expiredEvents as $expiredAsset) {
			$this->eventDispatcher?->dispatch(new DigitalAssetEvent($expiredAsset), DigitalAssetEvents::EXPIRED);
		}

		return [
			'notified' => $notified,
			'expired' => $expired,
			'madePrivate' => $madePrivate,
		];
	}

	private function sendNotification(string $subject, string $content, string $importance): void
	{
		if (null === $this->notifier) {
			return;
		}

		try {
			$notification = (new Notification($subject, ['email']))->
				content($content)->
				importance($importance);
			$this->notifier->send($notification);
		} catch (\Throwable $exception) {
			$this->logger?->warning('Failed to dispatch digital asset license expiry notification.', [
				'subject' => $subject,
				'error' => $exception->getMessage(),
			]);
		}
	}
}
