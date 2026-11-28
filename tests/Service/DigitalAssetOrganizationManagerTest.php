<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetOrganizationManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class DigitalAssetOrganizationManagerTest extends TestCase
{
	public function testUpdateFolderCascadesSubtreePathsOnRenameAndMove(): void
	{
		$root = new DigitalAssetFolder('Marketing');
		$this->assignId($root, 1);
		$child = new DigitalAssetFolder('Banners', $root);
		$this->assignId($child, 2);
		$grandchild = new DigitalAssetFolder('Mobile', $child);
		$this->assignId($grandchild, 3);
		$newParent = new DigitalAssetFolder('Archive');
		$this->assignId($newParent, 4);

		$folders = $this->createStub(DigitalAssetFolderRepository::class);
		$folders->method('findByParentAndSlug')->willReturn(null);
		$folders->method('find')->willReturnMap([
			['4', null, null, [], $newParent],
		]);
		$folders->method('findDescendantsByPathPrefix')->willReturnCallback(
			static fn (string $prefix): array => match ($prefix) {
				'/marketing' => [$child, $grandchild],
				'/brand' => [$child, $grandchild],
				default => [],
			},
		);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::exactly(2))->method('flush');

		$manager = $this->createManager($em, folders: $folders);
		$admin = $this->user([0 => ['ROLE_SYSTEM']], 99);

		$manager->updateFolder($root, ['name' => 'Brand'], $admin);
		self::assertSame('/brand', $root->getPath());
		self::assertSame('/brand/banners', $child->getPath());
		self::assertSame('/brand/banners/mobile', $grandchild->getPath());

		$manager->updateFolder($root, ['parentId' => '4'], $admin);
		self::assertSame('/archive/brand', $root->getPath());
		self::assertSame('/archive/brand/banners', $child->getPath());
		self::assertSame('/archive/brand/banners/mobile', $grandchild->getPath());
	}

	public function testUpdateFolderRejectsMovingIntoOwnDescendant(): void
	{
		$root = new DigitalAssetFolder('Root');
		$this->assignId($root, 1);
		$child = new DigitalAssetFolder('Child', $root);
		$this->assignId($child, 2);
		$grandchild = new DigitalAssetFolder('Grandchild', $child);
		$this->assignId($grandchild, 3);

		$folders = $this->createStub(DigitalAssetFolderRepository::class);
		$folders->method('find')->willReturn($grandchild);

		$manager = $this->createManager($this->createStub(EntityManagerInterface::class), folders: $folders);
		$admin = $this->user([0 => ['ROLE_SYSTEM']], 99);

		$this->expectException(\InvalidArgumentException::class);
		$manager->updateFolder($root, ['parentId' => 3], $admin);
	}

	public function testSiteAdminCannotModifyGlobalOrOtherSiteFolder(): void
	{
		$siteA = $this->site(10);
		$siteB = $this->site(20);
		$globalFolder = new DigitalAssetFolder('Global');
		$this->assignId($globalFolder, 1);
		$otherSiteFolder = new DigitalAssetFolder('Other');
		$this->assignId($otherSiteFolder, 2);
		$otherSiteFolder->setAccessSites([$siteB]);
		$ownSiteFolder = new DigitalAssetFolder('Mine');
		$this->assignId($ownSiteFolder, 3);
		$ownSiteFolder->setAccessSites([$siteA]);

		$manager = $this->createManager($this->createStub(EntityManagerInterface::class));
		$siteAdmin = $this->user(['10' => ['ROLE_ADMIN']], 5);

		self::assertFalse($manager->canManageFolder($siteAdmin, $globalFolder));
		self::assertFalse($manager->canManageFolder($siteAdmin, $otherSiteFolder));
		self::assertTrue($manager->canManageFolder($siteAdmin, $ownSiteFolder));
		self::assertTrue($manager->canUseFolder($siteAdmin, $globalFolder));
		self::assertFalse($manager->canUseFolder($siteAdmin, $otherSiteFolder));

		$this->expectException(AccessDeniedHttpException::class);
		$manager->updateFolder($otherSiteFolder, ['name' => 'Hijacked'], $siteAdmin);
	}

	public function testFindOrCreateTagReusesExistingCaseInsensitiveMatch(): void
	{
		$existing = new DigitalAssetTag('Product Shots', 'asset', 'product-shots');
		$this->assignId($existing, 42);

		$tags = $this->createStub(DigitalAssetTagRepository::class);
		$tags->method('findOneByNamespaceAndSlug')->willReturnMap([
			['asset', 'product-shots', $existing],
			['asset', 'new-tag', null],
		]);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::once())->method('persist');
		$em->expects(self::once())->method('flush');

		$manager = $this->createManager($em, tags: $tags);
		$admin = $this->user([0 => ['ROLE_SYSTEM']], 1);

		$reused = $manager->findOrCreateTag('PRODUCT SHOTS', $admin, 'ASSET');
		self::assertSame($existing, $reused);

		$created = $manager->findOrCreateTag('New Tag', $admin);
		self::assertSame('new-tag', $created->getSlug());
		self::assertSame('asset', $created->getNamespace());
	}

	public function testAddAssetsToCollectionEnforcesAssetAccessPolicy(): void
	{
		$siteA = $this->site(10);
		$siteB = $this->site(20);
		$assetB = new UploadedDigitalAsset('Site B Only', 'image/png', str_repeat('a', 64));
		$this->assignId($assetB, 100);
		$assetB->setAccessSites([$siteB]);

		$assets = $this->createStub(DigitalAssetRepository::class);
		$assets->method('find')->willReturn($assetB);

		$collection = new DigitalAssetCollection('Featured');
		$this->assignId($collection, 1);

		$manager = $this->createManager($this->createStub(EntityManagerInterface::class), assets: $assets);
		$siteAdmin = $this->user(['10' => ['ROLE_ADMIN']], 5);

		$this->expectException(AccessDeniedHttpException::class);
		$manager->addAssetsToCollection($collection, ['100'], $siteAdmin);
	}

	private function createManager(
		EntityManagerInterface $em,
		?DigitalAssetFolderRepository $folders = null,
		?DigitalAssetCollectionRepository $collections = null,
		?DigitalAssetTagRepository $tags = null,
		?DigitalAssetRepository $assets = null,
	): DigitalAssetOrganizationManager {
		$siteRepo = $this->createStub(SiteRepository::class);
		$hierarchy = $this->createStub(RoleHierarchyInterface::class);
		$hierarchy->method('getReachableRoleNames')->willReturnCallback(static fn (array $roles): array => array_values($roles));
		$policy = new DigitalAssetAccessPolicy($siteRepo, $hierarchy);

		return new DigitalAssetOrganizationManager(
			$em,
			$folders ?? $this->createStub(DigitalAssetFolderRepository::class),
			$collections ?? $this->createStub(DigitalAssetCollectionRepository::class),
			$tags ?? $this->createStub(DigitalAssetTagRepository::class),
			$assets ?? $this->createStub(DigitalAssetRepository::class),
			$siteRepo,
			$this->createStub(SiteRegistry::class),
			$policy,
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
