<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexInterface;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageReference;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Exception\AssetInUseException;
use iikiti\CMS\Exception\DuplicateAssetException;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Repository\Workflow\WorkflowInstanceRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetDuplicateAndRightsTest extends TestCase
{
	public function testLicenseExpirationAndEffectivePrivacyHelpers(): void
	{
		$now = new \DateTimeImmutable('2026-06-01T12:00:00Z');
		$asset = new UploadedDigitalAsset('Licensed Photo', 'image/jpeg', str_repeat('a', 64));
		$asset->setVisibility('public');

		self::assertFalse($asset->isExpired($now));
		self::assertFalse($asset->isExpiringSoon(14, $now));
		self::assertFalse($asset->isEffectivelyPrivate($now));

		// Expiring in 5 days -> expiring soon, not yet expired
		$asset->setExpiresAt(new \DateTimeImmutable('2026-06-06T12:00:00Z'));
		$asset->setExpiryAction('make_private');
		self::assertFalse($asset->isExpired($now));
		self::assertTrue($asset->isExpiringSoon(14, $now));
		self::assertFalse($asset->isEffectivelyPrivate($now));

		// Expired with make_private -> immediately effectively private at request time
		$asset->setExpiresAt(new \DateTimeImmutable('2026-06-01T11:59:59Z'));
		self::assertTrue($asset->isExpired($now));
		self::assertFalse($asset->isExpiringSoon(14, $now));
		self::assertTrue($asset->isEffectivelyPrivate($now));

		// Expired with alert -> remains public
		$asset->setExpiryAction('alert');
		self::assertTrue($asset->isExpired($now));
		self::assertFalse($asset->isEffectivelyPrivate($now));
	}

	public function testDuplicatePolicyRejectAndReuseScopedToSite(): void
	{
		$site = $this->site(10);
		$existing = new UploadedDigitalAsset('Existing PNG', 'image/png', str_repeat('b', 64));
		$this->assignId($existing, 77);
		$existing->setAccessSites([$site]);

		$tmpPng = $this->createMinimalPngTempFile();
		try {
			$checksum = (string) hash_file('sha256', $tmpPng);
			$existing->setChecksum($checksum);

			$assetRepo = $this->createStub(DigitalAssetRepository::class);
			$assetRepo->method('findByChecksumForSites')->willReturnCallback(
				static fn (string $c, array $siteIds, bool $isGlobal): array => $c === $checksum && in_array(10, array_map('intval', $siteIds), true)
					? [$existing]
					: [],
			);

			$manager = $this->createManager([$site], assets: $assetRepo);
			$user = $this->adminUser(['10' => ['ROLE_ADMIN']], 1);

			// Policy = reuse returns existing without uploading
			$reused = $manager->createUploadForSite(
				new UploadedFile($tmpPng, 'dup.png', 'image/png', null, true),
				'New Name',
				'public',
				$site,
				$user,
				null,
				[],
				DigitalAssetManager::DUPLICATE_POLICY_REUSE,
			);
			self::assertSame($existing, $reused);

			// Policy = reject throws DuplicateAssetException with matching asset
			try {
				$manager->createUploadForSite(
					new UploadedFile($tmpPng, 'dup.png', 'image/png', null, true),
					'New Name',
					'public',
					$site,
					$user,
					null,
					[],
					DigitalAssetManager::DUPLICATE_POLICY_REJECT,
				);
				self::fail('Expected DuplicateAssetException to be thrown.');
			} catch (DuplicateAssetException $exception) {
				self::assertCount(1, $exception->getMatches());
				self::assertSame($existing, $exception->getMatches()[0]);
			}
		} finally {
			@unlink($tmpPng);
		}
	}

	public function testDeleteThrowsAssetInUseExceptionUnlessForced(): void
	{
		$site = $this->site(10);
		$asset = new UploadedDigitalAsset('In Use Banner', 'image/png', str_repeat('c', 64));
		$this->assignId($asset, 55);
		$asset->setAccessSites([$site]);
		$asset->setStorageKey('ab/cd/in-use.png');

		$usageIndex = $this->createStub(AssetUsageIndexInterface::class);
		$usageIndex->method('findUsage')->with(55)->willReturn([
			new AssetUsageReference('page', 9, 'About Us', 'dynamic_blocks.main[0].image', false, 10, '/admin#/pages/9'),
		]);

		$storage = $this->createMock(AssetStorageInterface::class);
		$storage->expects(self::once())->method('delete')->with('ab/cd/in-use.png');

		$manager = $this->createManager([$site], usageIndex: $usageIndex, storage: $storage);
		$admin = $this->adminUser(['10' => ['ROLE_ADMIN']], 1);

		try {
			$manager->delete($asset, $admin, false);
			self::fail('Expected AssetInUseException to be thrown.');
		} catch (AssetInUseException $exception) {
			self::assertSame(55, $exception->getAssetId());
			self::assertSame(1, $exception->getUsageCount());
			self::assertSame('page', $exception->getUsages()[0]['contextType']);
		}

		// Forced deletion succeeds
		$manager->delete($asset, $admin, true);
	}

	public function testUpdateLicenseResetsNotificationTimestampsWhenExpiryExtended(): void
	{
		$site = $this->site(10);
		$asset = new UploadedDigitalAsset('Contract Photo', 'image/jpeg', str_repeat('d', 64));
		$this->assignId($asset, 88);
		$asset->setAccessSites([$site]);
		$asset->setExpiresAt(new \DateTimeImmutable('2026-05-01T00:00:00Z'));
		$asset->setExpiryNotifiedAt(new \DateTimeImmutable('2026-04-20T00:00:00Z'));
		$asset->setExpiredHandledAt(new \DateTimeImmutable('2026-05-01T01:00:00Z'));

		$manager = $this->createManager([$site]);
		$admin = $this->adminUser(['10' => ['ROLE_ADMIN']], 1);

		$manager->update($asset, [
			'license' => [
				'type' => 'Rights-Managed',
				'holder' => 'Acme Stock',
				'url' => 'https://example.com/license/123',
				'notes' => 'Renewed for 2027',
				'expiresAt' => '2027-05-01T00:00:00Z',
				'expiryAction' => 'make_private',
			],
		], $admin);

		self::assertSame('Rights-Managed', $asset->getLicenseType());
		self::assertSame('Acme Stock', $asset->getLicenseHolder());
		self::assertSame('https://example.com/license/123', $asset->getLicenseUrl());
		self::assertSame('Renewed for 2027', $asset->getLicenseNotes());
		self::assertSame('make_private', $asset->getExpiryAction());
		self::assertNull($asset->getExpiryNotifiedAt());
		self::assertNull($asset->getExpiredHandledAt());
	}

	/**
	 * @param list<Site> $sites
	 */
	private function createManager(
		array $sites,
		?DigitalAssetRepository $assets = null,
		?AssetUsageIndexInterface $usageIndex = null,
		?AssetStorageInterface $storage = null,
	): DigitalAssetManager {
		$siteRepo = $this->createStub(SiteRepository::class);
		$siteRepo->method('findBy')->willReturn($sites);
		$roleHierarchy = $this->createStub(RoleHierarchyInterface::class);
		$roleHierarchy->method('getReachableRoleNames')->willReturnCallback(static fn (array $roles): array => $roles);
		$accessPolicy = new DigitalAssetAccessPolicy($siteRepo, $roleHierarchy);

		$workflowRepo = $this->createStub(WorkflowInstanceRepository::class);
		$workflowRepo->method('findBySubject')->willReturn([]);

		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('getRepository')->willReturn($workflowRepo);

		$variantRepo = $this->createStub(DigitalAssetVariantRepository::class);
		$variantRepo->method('findByAsset')->willReturn([]);

		$dispatcher = $this->createStub(EventDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnArgument(0);

		return new DigitalAssetManager(
			$em,
			$variantRepo,
			$siteRepo,
			new SiteRegistry(),
			$accessPolicy,
			$storage ?? $this->createStub(AssetStorageInterface::class),
			$this->createStub(MessageBusInterface::class),
			$dispatcher,
			new AssetProcessingStateMachine(StateMachineFactory::assetProcessing()),
			null,
			null,
			null,
			$assets,
			null,
			$usageIndex,
		);
	}

	private function createMinimalPngTempFile(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'iikiti_dup_test_');
		if (false === $path) {
			throw new \RuntimeException('Failed to create temp file.');
		}
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
		file_put_contents($path, $png);

		return $path;
	}

	private function site(int $id): Site
	{
		$site = new Site();
		$this->assignId($site, $id);
		$site->setProperties(new ArrayCollection());

		return $site;
	}

	/** @param array<string,list<string>> $rolesBySite */
	private function adminUser(array $rolesBySite, int $id): User
	{
		$user = new User();
		$this->assignId($user, $id);
		$user->setProperties(new ArrayCollection());
		$user->setProperty('roles', $rolesBySite);

		return $user;
	}

	private function assignId(DbObject $object, int $id): void
	{
		$ref = new \ReflectionProperty(DbObject::class, 'id');
		$ref->setValue($object, $id);
	}
}
