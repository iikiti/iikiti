<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api\Editor;

use iikiti\CMS\Controller\Api\Editor\EditorAssetController;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\Service\DigitalAssetSerializer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class EditorAssetControllerTest extends TestCase
{
	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);

		parent::tearDown();
	}

	public function testIndexForbidsUnauthenticatedOrUnauthorizedUsers(): void
	{
		$this->pushCurrentSite(10);
		$assets = $this->createStub(DigitalAssetRepository::class);
		$serializer = new DigitalAssetSerializer();
		$checker = $this->permissionChecker(static fn (string $type, string $action): bool => false);

		$response = $this->controller(new User())->index(
			Request::create('/api/editor/assets', 'GET'),
			$checker,
			$assets,
			$serializer,
		);

		self::assertSame(403, $response->getStatusCode());
	}

	public function testIndexAllowsPageEditorWithoutAdminRoleAndScopesToCurrentSite(): void
	{
		$site = $this->pushCurrentSite(10);
		$asset = $this->makeAsset(55, 'Banner', 'image/jpeg', $site);

		$assets = $this->createMock(DigitalAssetRepository::class);
		$assets->expects(self::once())
			->method('findAccessibleToSiteByCriteria')
			->with(
				$site,
				self::callback(static fn ($criteria): bool => 'banner' === $criteria->query
					&& 'image' === $criteria->mimeCategory
					&& 'image/jpeg' === $criteria->mimeType
					&& null === $criteria->siteId),
			)
			->willReturn([$asset]);
		$assets->expects(self::once())
			->method('countAccessibleToSiteByCriteria')
			->with(
				$site,
				self::callback(static fn ($criteria): bool => null === $criteria->siteId),
			)
			->willReturn(1);

		$checker = $this->permissionChecker(static fn (string $type, string $action): bool => 'Page' === $type && 'write' === $action);
		$response = $this->controller(new User())->index(
			Request::create('/api/editor/assets?q=banner&mimeCategory=image&mimeType=image/jpeg&siteId=999', 'GET'),
			$checker,
			$assets,
			new DigitalAssetSerializer(),
		);

		self::assertSame(200, $response->getStatusCode());
		/** @var array<string,mixed> $body */
		$body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(1, $body['totalItems']);
		self::assertSame(1, $body['page']);
		self::assertSame(24, $body['itemsPerPage']);
		self::assertCount(1, $body['assets']);
		self::assertSame(55, $body['assets'][0]['id']);
		self::assertSame('/assets/'.str_repeat('a', 64).'/original', $body['assets'][0]['fileUrl']);
		self::assertNotNull($body['assets'][0]['thumbnailUrl']);
		self::assertNotNull($body['assets'][0]['previewUrl']);
	}

	public function testShowReturns404WhenAssetNotGrantedToCurrentSite(): void
	{
		$site = $this->pushCurrentSite(10);
		$assets = $this->createMock(DigitalAssetRepository::class);
		$assets->expects(self::once())
			->method('findAccessibleToSite')
			->with(999, $site)
			->willReturn(null);

		$checker = $this->permissionChecker(static fn (string $type, string $action): bool => 'Template' === $type && 'write' === $action);
		$response = $this->controller(new User())->show(
			999,
			Request::create('/api/editor/assets/999', 'GET'),
			$checker,
			$assets,
			new DigitalAssetSerializer(),
		);

		self::assertSame(404, $response->getStatusCode());
	}

	public function testUploadDefaultsToPublicVisibilityAndCurrentSiteGrant(): void
	{
		$site = $this->pushCurrentSite(10);
		$user = new User();
		$uploadedAsset = $this->makeAsset(77, 'Hero Photo', 'image/png', $site);

		$tmpFile = tempnam(sys_get_temp_dir(), 'iikiti_editor_upload_');
		self::assertIsString($tmpFile);
		file_put_contents($tmpFile, 'fake-png-bytes');
		try {
			$file = new UploadedFile($tmpFile, 'hero.png', 'image/png', null, true);

			$manager = $this->createMock(DigitalAssetManager::class);
			$manager->expects(self::once())
				->method('createUploadForSite')
				->with($file, 'Hero Photo', 'public', $site, $user, null, ['3', '4'], DigitalAssetManager::DUPLICATE_POLICY_ALLOW)
				->willReturn($uploadedAsset);

			$checker = $this->permissionChecker(static fn (string $type, string $action): bool => 'Page' === $type && 'write' === $action);
			$request = Request::create('/api/editor/assets/upload', 'POST', ['title' => 'Hero Photo', 'tagIds' => ['3', '4']], [], ['file' => $file]);

			$response = $this->controller($user)->upload($request, $checker, $manager, new DigitalAssetSerializer());
		} finally {
			@unlink($tmpFile);
		}

		self::assertSame(201, $response->getStatusCode());
		/** @var array<string,mixed> $body */
		$body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(77, $body['id']);
		self::assertSame('public', $body['visibility']);
		self::assertSame([10], $body['siteIds']);
	}

	private function pushCurrentSite(int $id): Site
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, $id);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		SiteRegistry::pushSite($site);

		return $site;
	}

	private function makeAsset(int $id, string $title, string $mimeType, Site $site): UploadedDigitalAsset
	{
		$asset = new UploadedDigitalAsset($title, $mimeType, str_repeat('a', 64));
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($asset, $id);
		$asset->setVisibility('public');
		$asset->setAccessSites([$site]);
		$asset->setWidth(1200)->setHeight(630);

		return $asset;
	}

	/** @param callable(string,string):bool $check */
	private function permissionChecker(callable $check): PermissionChecker
	{
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturnCallback(
			static fn (User $user, string $type, string $action): bool => $check($type, $action),
		);

		return $checker;
	}

	private function controller(User $user): EditorAssetController
	{
		$tokenStorage = $this->createStub(TokenStorageInterface::class);
		$tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', []));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => 'security.token_storage' === $id);
		$container->method('get')->willReturnCallback(static fn (string $id): mixed => 'security.token_storage' === $id ? $tokenStorage : null);
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);

		return new EditorAssetController($security, $container);
	}
}
