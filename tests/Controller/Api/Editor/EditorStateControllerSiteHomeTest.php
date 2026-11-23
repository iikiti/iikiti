<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api\Editor;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Controller\Api\Editor\EditorStateController;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\Template\TemplateResolver;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Security\HomepageSeoAccessPolicy;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Web\ContentOptimizationEditorSettingsHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

require_once dirname(__DIR__, 4).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 4).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 4).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorRegistry.php';
require_once dirname(__DIR__, 4).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';

final class EditorStateControllerSiteHomeTest extends TestCase
{
	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);

		parent::tearDown();
	}

	public function testSiteHomeRequiresTheSiteAndEditorPublishPermissions(): void
	{
		$user = new User();
		$site = new Site();
		$workflow = $this->globalAdminPolicy();

		$noPublish = $this->permissionChecker(static fn (string $type, string $action): bool => false);
		self::assertFalse((new HomepageSeoAccessPolicy($noPublish, $workflow))->canEdit($user, $site));

		$templatePublisher = $this->permissionChecker(static fn (string $type, string $action): bool => 'Template' === $type && 'publish' === $action);
		self::assertTrue((new HomepageSeoAccessPolicy($templatePublisher, $workflow))->canEdit($user, $site));

		// Unpersisted default template (id === null) must normalize to null for object-level ACL checks
		$observedObject = 'unset';
		$objectAwareChecker = $this->createStub(PermissionChecker::class);
		$objectAwareChecker->method('canAccess')->willReturnCallback(
			static function (User $u, string $type, string $action, ?DbObject $obj = null) use (&$observedObject): bool {
				if ('Template' === $type && 'publish' === $action) {
					$observedObject = $obj;
					return true;
				}
				return false;
			},
		);
		self::assertTrue((new HomepageSeoAccessPolicy($objectAwareChecker, $workflow))->canEdit($user, $site, new Template()));
		self::assertNull($observedObject);

		$configuration = $site->getConfiguration();
		$configuration->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		$site->setConfiguration($configuration);
		self::assertFalse((new HomepageSeoAccessPolicy($templatePublisher, $workflow))->canEdit($user, $site));
	}

	public function testSiteHomeSeoSaveNormalizesValuesAndPreservesExistingSettings(): void
	{
		$site = $this->currentSite();
		$site->setProperty('settings_draft', ['themeOption' => 'preserved', 'pluginSetting' => ['enabled' => true]]);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('persist')->with($site);
		$entityManager->expects(self::once())->method('flush');
		$settingsManager = new EditorSettingsManager($entityManager);
		$audit = $this->createMock(AuditRecorder::class);
		$audit->expects(self::once())->method('record');
		$user = new User();

		$response = $this->controller($user, $checker = $this->permissionChecker(static fn (string $type, string $action): bool => 'Template' === $type && 'publish' === $action), $this->globalAdminPolicy(), $settingsManager)->saveSettings(
			$this->request('/api/editor/settings/save', [
				'targetType' => 'site_home',
				'targetId' => 42,
				'settings' => [
					'name' => 'Client supplied name',
					'customCss' => '.client { color: red; }',
					'iikiti_content_optimization_title' => '  Homepage title  ',
					'iikiti_content_optimization_description' => '  Homepage description  ',
				],
			]),
			$checker,
			$settingsManager,
			$this->globalAdminPolicy(),
			$audit,
		);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame([
			'themeOption' => 'preserved',
			'pluginSetting' => ['enabled' => true],
			'iikiti_content_optimization_title' => 'Homepage title',
			'iikiti_content_optimization_description' => 'Homepage description',
		], $settingsManager->state($site)['values']);
	}

	public function testSiteHomeSettingsRejectsAnotherSitesTargetId(): void
	{
		$this->currentSite();
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::never())->method('persist');
		$entityManager->expects(self::never())->method('flush');
		$checker = $this->permissionChecker(static fn (string $type, string $action): bool => true);
		$settingsManager = new EditorSettingsManager($entityManager);
		$workflow = $this->globalAdminPolicy();

		$response = $this->controller(new User(), $checker, $workflow, $settingsManager)->saveSettings(
			$this->request('/api/editor/settings/save', [
				'targetType' => 'site_home',
				'targetId' => 43,
				'settings' => [],
			]),
			$checker,
			$settingsManager,
			$workflow,
		);

		self::assertSame(404, $response->getStatusCode());
	}

	public function testSiteManagerWithoutTemplatePublishPermissionCannotPublishSiteHome(): void
	{
		$site = $this->currentSite();
		$user = new User();
		$user->setProperty('roles', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$roleHierarchy = $this->createStub(RoleHierarchyInterface::class);
		$roleHierarchy->method('getReachableRoleNames')->willReturnCallback(static fn (array $roles): array => $roles);
		$workflow = $this->workflowPolicy($roleHierarchy);
		self::assertTrue($workflow->canManageSite($user, $site));
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::never())->method('persist');
		$entityManager->expects(self::never())->method('flush');
		$checker = $this->permissionChecker(static fn (string $type, string $action): bool => false);
		$settingsManager = new EditorSettingsManager($entityManager);

		$response = $this->controller($user, $checker, $workflow, $settingsManager)->publishSettings(
			$this->request('/api/editor/settings/publish', ['targetType' => 'site_home', 'targetId' => 42]),
			$checker,
			$settingsManager,
			$workflow,
		);

		self::assertSame(403, $response->getStatusCode());
	}

	public function testUnauthenticatedAndNonManagerCallsFailClosedWithoutEnumeratingTargets(): void
	{
		$this->currentSite();
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::never())->method('persist');

		// Unauthenticated
		$emptyTokenStorage = $this->createStub(TokenStorageInterface::class);
		$emptyTokenStorage->method('getToken')->willReturn(null);
		$emptyContainer = $this->createStub(ContainerInterface::class);
		$emptyContainer->method('has')->willReturnCallback(static fn (string $id): bool => 'security.token_storage' === $id);
		$emptyContainer->method('get')->willReturnCallback(static fn (string $id): mixed => 'security.token_storage' === $id ? $emptyTokenStorage : null);
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(null);
		$checker = $this->permissionChecker(static fn (): bool => true);
		$settingsManager = new EditorSettingsManager($entityManager);
		$workflow = $this->globalAdminPolicy();
		$unauthController = new EditorStateController(
			$security,
			$emptyContainer,
			[new ContentOptimizationEditorSettingsHandler(new PageSeoSettingsNormalizer(), $checker, $workflow, $settingsManager)],
		);
		$unauthResponse = $unauthController->saveSettings(
			$this->request('/api/editor/settings/save', ['targetType' => 'site_home', 'targetId' => 999, 'settings' => []]),
			$checker,
			$settingsManager,
			$workflow,
		);
		self::assertSame(403, $unauthResponse->getStatusCode());

		// Authenticated non-manager querying mismatched site ID must still receive 403, not 404
		$regularUser = new User();
		$roleHierarchy = $this->createStub(RoleHierarchyInterface::class);
		$roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_USER']);
		$nonManagerWorkflow = $this->workflowPolicy($roleHierarchy);
		$nonManagerResponse = $this->controller($regularUser, $checker, $nonManagerWorkflow, $settingsManager)->saveSettings(
			$this->request('/api/editor/settings/save', ['targetType' => 'site_home', 'targetId' => 999, 'settings' => []]),
			$checker,
			$settingsManager,
			$nonManagerWorkflow,
		);
		self::assertSame(403, $nonManagerResponse->getStatusCode());
	}

	public function testPagePublishRenormalizesValidLegacyDraftAndRejectsInvalidLegacyDraft(): void
	{
		$this->currentSite();
		$page = new Page();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($page, 77);
		$page->setProperty('settings_draft', [
			'customCss' => '.hero { color: blue; }',
			'iikiti_content_optimization_title' => '  Legacy untrimmed title  ',
		]);
		$pageRepo = $this->createStub(\iikiti\CMS\Repository\Object\PageRepository::class);
		$pageRepo->method('find')->willReturnCallback(static fn (mixed $id): ?Page => 77 === $id ? $page : null);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(static fn (string $class) => Page::class === $class ? $pageRepo : null);
		$entityManager->expects(self::once())->method('persist')->with($page);
		$entityManager->expects(self::once())->method('flush');
		$settingsManager = new EditorSettingsManager($entityManager);
		$audit = $this->createMock(AuditRecorder::class);
		$audit->expects(self::once())->method('record');
		$checker = $this->permissionChecker(static fn (string $type, string $action): bool => 'Page' === $type && 'publish' === $action);
		$workflow = $this->globalAdminPolicy();

		$okResponse = $this->controller(new User(), $checker, $workflow, $settingsManager)->publishSettings(
			$this->request('/api/editor/settings/publish', ['targetType' => 'page', 'targetId' => 77]),
			$checker,
			$settingsManager,
			$workflow,
			$audit,
		);
		self::assertSame(200, $okResponse->getStatusCode());
		self::assertSame('Legacy untrimmed title', $settingsManager->publishedSettings($page)['iikiti_content_optimization_title']);
		self::assertSame('.hero { color: blue; }', $settingsManager->publishedSettings($page)['customCss']);

		// Invalid legacy draft fails with 400
		$badPage = new Page();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($badPage, 78);
		$badPage->setProperty('settings_draft', [
			'iikiti_content_optimization_canonical_url' => 'javascript:alert(1)',
		]);
		$badPageRepo = $this->createStub(\iikiti\CMS\Repository\Object\PageRepository::class);
		$badPageRepo->method('find')->willReturnCallback(static fn (mixed $id): ?Page => 78 === $id ? $badPage : null);
		$em2 = $this->createMock(EntityManagerInterface::class);
		$em2->method('getRepository')->willReturnCallback(static fn (string $class) => Page::class === $class ? $badPageRepo : null);
		$em2->expects(self::never())->method('flush');
		$badSettingsManager = new EditorSettingsManager($em2);

		$badResponse = $this->controller(new User(), $checker, $workflow, $badSettingsManager)->publishSettings(
			$this->request('/api/editor/settings/publish', ['targetType' => 'page', 'targetId' => 78]),
			$checker,
			$badSettingsManager,
			$workflow,
		);
		self::assertSame(400, $badResponse->getStatusCode());
	}

	private function globalAdminPolicy(): WorkflowAccessPolicy
	{
		$roleHierarchy = $this->createStub(RoleHierarchyInterface::class);
		$roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_SYSTEM']);

		return $this->workflowPolicy($roleHierarchy);
	}

	private function workflowPolicy(RoleHierarchyInterface $roleHierarchy): WorkflowAccessPolicy
	{
		$assetAccess = new DigitalAssetAccessPolicy($this->createStub(SiteRepository::class), $roleHierarchy);

		return new WorkflowAccessPolicy($assetAccess, $roleHierarchy);
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

	private function currentSite(): Site
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 42);
		$site->getConfiguration()->enablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		SiteRegistry::pushSite($site);

		return $site;
	}

	private function controller(
		User $user,
		PermissionChecker $checker,
		WorkflowAccessPolicy $workflow,
		EditorSettingsManager $settingsManager,
	): EditorStateController {
		$tokenStorage = $this->createStub(TokenStorageInterface::class);
		$tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', []));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => 'security.token_storage' === $id);
		$container->method('get')->willReturnCallback(static fn (string $id): mixed => 'security.token_storage' === $id ? $tokenStorage : null);
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);

		return new EditorStateController(
			$security,
			$container,
			[new ContentOptimizationEditorSettingsHandler(new PageSeoSettingsNormalizer(), $checker, $workflow, $settingsManager)],
		);
	}

	/** @param array<string,mixed> $payload */
	private function request(string $path, array $payload): Request
	{
		return Request::create($path, 'POST', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
	}
}
