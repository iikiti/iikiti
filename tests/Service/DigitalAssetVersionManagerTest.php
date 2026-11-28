<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\DigitalAssetVersion;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Message\ProcessDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\DigitalAssetVersionRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Repository\Workflow\WorkflowInstanceRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetVersionManager;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetVersionManagerTest extends TestCase
{
	public function testReplacePreservesIdentityAndTokenRecordsNextVersionAndRequeuesProcessing(): void
	{
		$site = $this->site(10);
		$token = str_repeat('a', 64);
		$asset = new UploadedDigitalAsset('Hero', 'image/png', $token);
		$this->assignId($asset, 101);
		$asset->setAccessSites([$site]);
		$asset->setStorageKey('ab/cd/v1.png');
		$asset->setOriginalName('hero-v1.png');
		$asset->setSize(1024);
		$asset->setChecksum(str_repeat('1', 64));
		$asset->setWidth(800);
		$asset->setHeight(600);
		$asset->setBlurhash('LEHV6nWB2yk8pyo0adR*.7kCMdnj');
		$asset->setDominantColor('#112233');
		$asset->setProcessingStatus('complete');

		$oldVariant = new DigitalAssetVariant($asset, 'thumbnail', 'ab/cd/thumb-v1.webp', 'image/webp', 500, 320, 240);

		$tmpPng = $this->createMinimalPngTempFile();
		try {
			$uploaded = new UploadedFile($tmpPng, 'hero-v2.png', 'image/png', null, true);

			$versionRepo = $this->createStub(DigitalAssetVersionRepository::class);
			$versionRepo->method('findMaxVersionNumber')->willReturn(1);
			$versionRepo->method('countStorageKeyReferences')->willReturn(0);

			$variantRepo = $this->createStub(DigitalAssetVariantRepository::class);
			$variantRepo->method('findByAsset')->willReturn([$oldVariant]);

			$workflowRepo = $this->createStub(WorkflowInstanceRepository::class);
			$workflowRepo->method('findBySubject')->willReturn([]);

			$em = $this->createMock(EntityManagerInterface::class);
			$em->method('getRepository')->willReturnCallback(
				static fn (string $class): object => WorkflowInstance::class === $class ? $workflowRepo : $versionRepo,
			);
			$em->method('createQueryBuilder')->willReturn($this->zeroCountQueryBuilder());
			$em->expects(self::once())->method('remove')->with($oldVariant);
			$em->expects(self::once())->method('persist')->with(self::isInstanceOf(DigitalAssetVersion::class));
			$em->expects(self::once())->method('flush');

			$storage = $this->createMock(AssetStorageInterface::class);
			$storage->expects(self::once())->method('storeUploadedFile')->willReturn('ef/01/v2.png');
			$storage->expects(self::once())->method('delete')->with('ab/cd/thumb-v1.webp');

			$bus = $this->createMock(MessageBusInterface::class);
			$bus->expects(self::once())->
				method('dispatch')->
				with(self::callback(static fn (object $msg): bool => $msg instanceof ProcessDigitalAsset && 101 === $msg->assetId))->
				willReturn(new Envelope(new \stdClass()));

			$dispatcher = $this->createMock(EventDispatcherInterface::class);
			$dispatcher->expects(self::once())->
				method('dispatch')->
				with(self::anything(), DigitalAssetEvents::REPLACED)->
				willReturnArgument(0);

			$manager = new DigitalAssetVersionManager(
				$em,
				$versionRepo,
				$variantRepo,
				$this->accessPolicy([$site]),
				$storage,
				$bus,
				$dispatcher,
				new AssetProcessingStateMachine(StateMachineFactory::assetProcessing()),
			);

			$result = $manager->replace($asset, $uploaded, $this->adminUser(['10' => ['ROLE_ADMIN']], 5), 'Sharper export');

			self::assertSame(101, $result['asset']->getId());
			self::assertSame($token, $result['asset']->getPublicToken());
			self::assertSame(2, $result['asset']->getCurrentVersion());
			self::assertSame(2, $result['version']->getVersionNumber());
			self::assertSame('ef/01/v2.png', $result['asset']->getStorageKey());
			self::assertSame('Sharper export', $result['version']->getChangeNote());
			self::assertNull($result['asset']->getWidth());
			self::assertNull($result['asset']->getHeight());
			self::assertNull($result['asset']->getBlurhash());
			self::assertNull($result['asset']->getDominantColor());
			self::assertSame('pending', $result['asset']->getProcessingStatus());
		} finally {
			@unlink($tmpPng);
		}
	}

	public function testReplaceRejectsCrossMimeFamilyUnlessExplicitlyAllowed(): void
	{
		$site = $this->site(10);
		$asset = new UploadedDigitalAsset('Manual', 'application/pdf', str_repeat('b', 64));
		$this->assignId($asset, 102);
		$asset->setAccessSites([$site]);
		$asset->setStorageKey('ab/cd/manual.pdf');

		$tmpPng = $this->createMinimalPngTempFile();
		try {
			$uploaded = new UploadedFile($tmpPng, 'photo.png', 'image/png', null, true);

			$manager = new DigitalAssetVersionManager(
				$this->createStub(EntityManagerInterface::class),
				$this->createStub(DigitalAssetVersionRepository::class),
				$this->createStub(DigitalAssetVariantRepository::class),
				$this->accessPolicy([$site]),
				$this->createStub(AssetStorageInterface::class),
				$this->createStub(MessageBusInterface::class),
				$this->createStub(EventDispatcherInterface::class),
				new AssetProcessingStateMachine(StateMachineFactory::assetProcessing()),
			);

			$this->expectException(\InvalidArgumentException::class);
			$manager->replace($asset, $uploaded, $this->adminUser(['10' => ['ROLE_ADMIN']], 5), null, false);
		} finally {
			@unlink($tmpPng);
		}
	}

	public function testRollbackCreatesAppendOnlyHeadVersionWithoutDeletingOriginalBlob(): void
	{
		$site = $this->site(10);
		$asset = new UploadedDigitalAsset('Banner', 'image/png', str_repeat('c', 64));
		$this->assignId($asset, 200);
		$asset->setAccessSites([$site]);
		$asset->setCurrentVersion(2);
		$asset->setStorageKey('key/v2.png');
		$asset->setProcessingStatus('complete');

		$v1 = new DigitalAssetVersion(
			asset: $asset,
			versionNumber: 1,
			storageKey: 'key/v1.png',
			originalName: 'banner-v1.png',
			mimeType: 'image/png',
			size: 2048,
			checksum: str_repeat('2', 64),
			width: 1200,
			height: 400,
		);

		$versionRepo = $this->createStub(DigitalAssetVersionRepository::class);
		$versionRepo->method('findByAssetAndVersion')->with($asset, 1)->willReturn($v1);
		$versionRepo->method('findMaxVersionNumber')->willReturn(2);
		$versionRepo->method('countStorageKeyReferences')->willReturn(1);

		$variantRepo = $this->createStub(DigitalAssetVariantRepository::class);
		$variantRepo->method('findByAsset')->willReturn([]);

		$workflowRepo = $this->createStub(WorkflowInstanceRepository::class);
		$workflowRepo->method('findBySubject')->willReturn([]);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->method('getRepository')->willReturn($workflowRepo);
		$em->expects(self::once())->method('persist')->with(self::callback(
			static fn (object $entity): bool => $entity instanceof DigitalAssetVersion
				&& 3 === $entity->getVersionNumber()
				&& 'key/v1.png' === $entity->getStorageKey(),
		));
		$em->expects(self::once())->method('flush');

		$storage = $this->createMock(AssetStorageInterface::class);
		$storage->method('exists')->with('key/v1.png')->willReturn(true);
		$storage->expects(self::never())->method('delete');

		$bus = $this->createMock(MessageBusInterface::class);
		$bus->expects(self::once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

		$dispatcher = $this->createStub(EventDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnArgument(0);

		$manager = new DigitalAssetVersionManager(
			$em,
			$versionRepo,
			$variantRepo,
			$this->accessPolicy([$site]),
			$storage,
			$bus,
			$dispatcher,
			new AssetProcessingStateMachine(StateMachineFactory::assetProcessing()),
		);

		$result = $manager->rollback($asset, 1, $this->adminUser(['10' => ['ROLE_ADMIN']], 5));

		self::assertSame(3, $result['version']->getVersionNumber());
		self::assertSame(3, $result['asset']->getCurrentVersion());
		self::assertSame('key/v1.png', $result['asset']->getStorageKey());
		self::assertSame('Rolled back to v1', $result['version']->getChangeNote());
		self::assertTrue($manager->isStorageKeyReferenced('key/v1.png'));
	}

	private function createMinimalPngTempFile(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'iikiti_ver_test_');
		if (false === $path) {
			throw new \RuntimeException('Failed to create temp file.');
		}
		// Valid 1x1 transparent PNG
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
		file_put_contents($path, $png);

		return $path;
	}

	private function zeroCountQueryBuilder(): QueryBuilder
	{
		$query = $this->createStub(Query::class);
		$query->method('getSingleScalarResult')->willReturn(0);

		$qb = $this->createStub(QueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setParameter')->willReturnSelf();
		$qb->method('getQuery')->willReturn($query);

		return $qb;
	}

	/** @param list<Site> $sites */
	private function accessPolicy(array $sites): DigitalAssetAccessPolicy
	{
		$siteRepo = $this->createStub(SiteRepository::class);
		$siteRepo->method('findBy')->willReturn($sites);
		$roleHierarchy = $this->createStub(RoleHierarchyInterface::class);
		$roleHierarchy->method('getReachableRoleNames')->willReturnCallback(static fn (array $roles): array => $roles);

		return new DigitalAssetAccessPolicy($siteRepo, $roleHierarchy);
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
