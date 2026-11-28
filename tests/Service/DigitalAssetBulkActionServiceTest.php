<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\ExternalDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Repository\Workflow\WorkflowInstanceRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetBulkActionService;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\Service\DigitalAssetOrganizationManager;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetBulkActionServiceTest extends TestCase
{
	public function testBulkTagAddEnforcesPerItemAccessWithoutAbortingValidItems(): void
	{
		$siteA = $this->site(10);
		$siteB = $this->site(20);

		$manageable = new UploadedDigitalAsset('Manageable', 'image/png', str_repeat('a', 64));
		$this->assignId($manageable, 101);
		$manageable->setAccessSites([$siteA]);

		$sharedRestricted = new UploadedDigitalAsset('Shared', 'image/png', str_repeat('b', 64));
		$this->assignId($sharedRestricted, 102);
		$sharedRestricted->setAccessSites([$siteA, $siteB]);

		$tag = new DigitalAssetTag('Featured');
		$this->assignId($tag, 5);

		$assets = $this->createStub(DigitalAssetRepository::class);
		$assets->method('find')->willReturnCallback(static fn (mixed $id): mixed => match ((string) $id) {
			'101' => $manageable,
			'102' => $sharedRestricted,
			default => null,
		});

		$tags = $this->createStub(DigitalAssetTagRepository::class);
		$tags->method('findByIds')->willReturn([$tag]);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::once())->method('flush');

		$dispatchedEvents = [];
		$dispatcher = $this->createStub(EventDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(static function (object $event, ?string $eventName = null) use (&$dispatchedEvents): object {
			$dispatchedEvents[] = [$event, $eventName];

			return $event;
		});

		$service = $this->createService($em, assets: $assets, tags: $tags, dispatcher: $dispatcher);
		$siteAdmin = $this->user(['10' => ['ROLE_ADMIN']], 7);

		$result = $service->execute(
			DigitalAssetBulkActionService::ACTION_TAG_ADD,
			['101', '102', '999'],
			['tagIds' => ['5']],
			$siteAdmin,
		);

		self::assertSame([101], $result['succeeded']);
		self::assertCount(2, $result['failed']);
		self::assertSame(102, $result['failed'][0]['id']);
		self::assertSame('999', $result['failed'][1]['id']);
		self::assertTrue($manageable->hasTag($tag));
		self::assertFalse($sharedRestricted->hasTag($tag));
		self::assertSame(['featured'], $manageable->getProperties()->get('tags')?->getValue());
		self::assertCount(1, $dispatchedEvents);
		self::assertInstanceOf(DigitalAssetEvent::class, $dispatchedEvents[0][0]);
		self::assertSame(DigitalAssetEvents::UPDATED, $dispatchedEvents[0][1]);
	}

	public function testBulkMoveFolderAndCollectionAndVisibilityOperations(): void
	{
		$siteA = $this->site(10);
		$asset = new UploadedDigitalAsset('Poster', 'image/jpeg', str_repeat('c', 64));
		$this->assignId($asset, 201);
		$asset->setAccessSites([$siteA]);

		$folder = new DigitalAssetFolder('Campaign');
		$this->assignId($folder, 30);
		$folder->setAccessSites([$siteA]);

		$collection = new DigitalAssetCollection('Summer');
		$this->assignId($collection, 40);
		$collection->setCreatorId(7);

		$assets = $this->createStub(DigitalAssetRepository::class);
		$assets->method('find')->willReturn($asset);

		$folders = $this->createStub(DigitalAssetFolderRepository::class);
		$folders->method('find')->willReturn($folder);

		$collections = $this->createStub(DigitalAssetCollectionRepository::class);
		$collections->method('find')->willReturn($collection);

		$service = $this->createService(
			$this->createStub(EntityManagerInterface::class),
			assets: $assets,
			folders: $folders,
			collections: $collections,
		);
		$admin = $this->user(['10' => ['ROLE_ADMIN']], 7);

		$moveRes = $service->execute(DigitalAssetBulkActionService::ACTION_MOVE_FOLDER, [201], ['folderId' => 30], $admin);
		self::assertSame([201], $moveRes['succeeded']);
		self::assertSame($folder, $asset->getFolder());

		$colAddRes = $service->execute(DigitalAssetBulkActionService::ACTION_COLLECTION_ADD, [201], ['collectionId' => 40], $admin);
		self::assertSame([201], $colAddRes['succeeded']);
		self::assertTrue($collection->hasAsset($asset));

		$visRes = $service->execute(DigitalAssetBulkActionService::ACTION_SET_VISIBILITY, [201], ['visibility' => 'public'], $admin);
		self::assertSame([201], $visRes['succeeded']);
		self::assertSame('public', $asset->getVisibility());

		$colRemRes = $service->execute(DigitalAssetBulkActionService::ACTION_COLLECTION_REMOVE, [201], ['collectionId' => 40], $admin);
		self::assertSame([201], $colRemRes['succeeded']);
		self::assertFalse($collection->hasAsset($asset));
	}

	public function testBulkCollectionActionsRejectUnauthorizedCollectionModification(): void
	{
		$siteA = $this->site(10);
		$asset = new UploadedDigitalAsset('Poster', 'image/jpeg', str_repeat('f', 64));
		$this->assignId($asset, 205);
		$asset->setAccessSites([$siteA]);

		$otherCollection = new DigitalAssetCollection('Other Admin Collection');
		$this->assignId($otherCollection, 41);
		$otherCollection->setCreatorId(99);

		$assets = $this->createStub(DigitalAssetRepository::class);
		$assets->method('find')->willReturn($asset);

		$collections = $this->createStub(DigitalAssetCollectionRepository::class);
		$collections->method('find')->willReturn($otherCollection);

		$service = $this->createService(
			$this->createStub(EntityManagerInterface::class),
			assets: $assets,
			collections: $collections,
		);
		$siteAdmin = $this->user(['10' => ['ROLE_ADMIN']], 7);

		$this->expectException(AccessDeniedHttpException::class);
		$service->execute(DigitalAssetBulkActionService::ACTION_COLLECTION_ADD, [205], ['collectionId' => 41], $siteAdmin);
	}

	public function testBulkReprocessRejectsExternalAssetsAndResetsCompletedWorkflows(): void
	{
		$siteA = $this->site(10);
		$uploaded = new UploadedDigitalAsset('Photo', 'image/png', str_repeat('d', 64));
		$this->assignId($uploaded, 301);
		$uploaded->setAccessSites([$siteA]);

		$external = new ExternalDigitalAsset('Remote', 'image/png', str_repeat('e', 64));
		$this->assignId($external, 302);
		$external->setAccessSites([$siteA]);

		$assets = $this->createStub(DigitalAssetRepository::class);
		$assets->method('find')->willReturnCallback(static fn (mixed $id): mixed => match ((string) $id) {
			'301' => $uploaded,
			'302' => $external,
			default => null,
		});

		$completedInstance = $this->createStub(WorkflowInstance::class);
		$workflowRepo = $this->createStub(WorkflowInstanceRepository::class);
		$workflowRepo->method('findBySubject')->with('digital_asset', 301)->willReturn([$completedInstance]);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->method('getRepository')->with(WorkflowInstance::class)->willReturn($workflowRepo);
		$em->expects(self::once())->method('remove')->with($completedInstance);

		$bus = $this->createMock(MessageBusInterface::class);
		$bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

		$service = $this->createService($em, assets: $assets, bus: $bus);
		$admin = $this->user([0 => ['ROLE_SYSTEM']], 1);

		$result = $service->execute(DigitalAssetBulkActionService::ACTION_REPROCESS, [301, 302], [], $admin);

		self::assertSame([301], $result['succeeded']);
		self::assertCount(1, $result['failed']);
		self::assertSame(302, $result['failed'][0]['id']);
	}

	public function testUnsupportedBulkActionThrowsInvalidArgumentException(): void
	{
		$service = $this->createService($this->createStub(EntityManagerInterface::class));
		$admin = $this->user([0 => ['ROLE_SYSTEM']], 1);

		$this->expectException(\InvalidArgumentException::class);
		$service->execute('unknown_action', [1], [], $admin);
	}

	private function createService(
		EntityManagerInterface $em,
		?DigitalAssetRepository $assets = null,
		?DigitalAssetFolderRepository $folders = null,
		?DigitalAssetCollectionRepository $collections = null,
		?DigitalAssetTagRepository $tags = null,
		?MessageBusInterface $bus = null,
		?EventDispatcherInterface $dispatcher = null,
	): DigitalAssetBulkActionService {
		$siteRepo = $this->createStub(SiteRepository::class);
		$hierarchy = $this->createStub(RoleHierarchyInterface::class);
		$hierarchy->method('getReachableRoleNames')->willReturnCallback(static fn (array $roles): array => array_values($roles));
		$policy = new DigitalAssetAccessPolicy($siteRepo, $hierarchy);
		$siteRegistry = $this->createStub(SiteRegistry::class);

		$foldersRepo = $folders ?? $this->createStub(DigitalAssetFolderRepository::class);
		$collectionsRepo = $collections ?? $this->createStub(DigitalAssetCollectionRepository::class);
		$tagsRepo = $tags ?? $this->createStub(DigitalAssetTagRepository::class);
		$assetsRepo = $assets ?? $this->createStub(DigitalAssetRepository::class);

		$messageBus = $bus ?? $this->createStub(MessageBusInterface::class);
		if (null === $bus) {
			$messageBus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
		}

		$eventDispatcher = $dispatcher ?? $this->createStub(EventDispatcherInterface::class);

		$assetManager = new DigitalAssetManager(
			$em,
			$this->createStub(DigitalAssetVariantRepository::class),
			$siteRepo,
			$siteRegistry,
			$policy,
			$this->createStub(AssetStorageInterface::class),
			$messageBus,
			$eventDispatcher,
			StateMachineFactory::wire(new AssetProcessingStateMachine()),
			null,
			$foldersRepo,
			$tagsRepo,
		);

		$orgManager = new DigitalAssetOrganizationManager(
			$em,
			$foldersRepo,
			$collectionsRepo,
			$tagsRepo,
			$assetsRepo,
			$siteRepo,
			$siteRegistry,
			$policy,
		);

		return new DigitalAssetBulkActionService(
			$em,
			$assetsRepo,
			$foldersRepo,
			$collectionsRepo,
			$tagsRepo,
			$siteRepo,
			$policy,
			$assetManager,
			$orgManager,
			null,
			$eventDispatcher,
		);
	}

	private function assignId(DbObject $object, int $id): void
	{
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($object, $id);
	}

	private function site(int $id): Site
	{
		$site = new Site();
		$this->assignId($site, $id);

		return $site;
	}

	/** @param array<int|string,list<string>> $roles */
	private function user(array $roles, int $id): User
	{
		$user = new User();
		$this->assignId($user, $id);
		$user->setProperties(new ArrayCollection());
		$user->setProperty('roles', $roles);

		return $user;
	}
}
