<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Plugin\Lifecycle\PluginLifecycleHandler;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Controller\Api\Admin\ContentOptimizationSettingsController;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

require_once dirname(__DIR__, 4).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 4).'/plugins/iikiti-content-optimization/src/Controller/Api/Admin/ContentOptimizationSettingsController.php';

#[AllowMockObjectsWithoutExpectations]
final class ContentOptimizationSettingsControllerTest extends TestCase
{
	private Application $application;
	private Site $site;
	private ApplicationRepository&MockObject $applications;
	private SiteRepository&MockObject $sites;
	private EntityManagerInterface&MockObject $entityManager;

	protected function setUp(): void
	{
		$this->application = new Application();
		$this->site = new Site();
		(new \ReflectionProperty(Site::class, 'id'))->setValue($this->site, 42);
		(new \ReflectionProperty(Application::class, 'id'))->setValue($this->application, 7);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		SiteRegistry::pushSite($this->site);

		$this->applications = $this->createMock(ApplicationRepository::class);
		$this->applications->method('find')->willReturnCallback(fn ($id) => 7 === (int) $id ? $this->application : null);
		$this->sites = $this->createMock(SiteRepository::class);
		$this->sites->method('getApplicationBySite')->willReturn($this->application);
		$this->entityManager = $this->createMock(EntityManagerInterface::class);
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		parent::tearDown();
	}

	public function testApplicationScopeRequiresGlobalAdministrator(): void
	{
		$this->expectException(AccessDeniedHttpException::class);
		$this->controller()->get($this->request('GET', [], true));
	}

	public function testSiteScopeRequiresTheCurrentSiteIdToMatchExactly(): void
	{
		$this->expectException(NotFoundHttpException::class);
		$this->controller(['ROLE_ADMIN'])->get($this->request('GET', [], false, '042'));
	}

	public function testGlobalAdministratorUpdatesTheResolvedApplication(): void
	{
		$this->entityManager->expects(self::once())->method('persist')->with($this->application);
		$this->entityManager->expects(self::once())->method('flush');

		$response = $this->controller(['ROLE_ADMIN'])->put($this->request('PUT', (new ContentOptimizationSettings())->defaults(), true));
		$data = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame(7, $data['applicationId']);
		self::assertSame('{pageTitle} | {siteName}', $data['seo']['titleTemplate']);
		self::assertSame([], $data['availableConnectors']);
	}

	public function testApplicationAndSiteGetResponsesIncludeAvailableConnectorMetadata(): void
	{
		$controller = $this->controller(['ROLE_ADMIN']);
		$applicationResponse = $controller->get($this->request('GET', [], true));
		$siteResponse = $controller->get($this->request('GET'));
		$applicationData = json_decode($applicationResponse->getContent(), true, 64, JSON_THROW_ON_ERROR);
		$siteData = json_decode($siteResponse->getContent(), true, 64, JSON_THROW_ON_ERROR);

		self::assertArrayHasKey('availableConnectors', $applicationData);
		self::assertArrayHasKey('availableConnectors', $siteData);
		self::assertSame([], $applicationData['availableConnectors']);
		self::assertSame([], $siteData['availableConnectors']);
	}

	public function testSiteSettingsExposeSharedXRobotsTagRulesWithoutCopyingThem(): void
	{
		$headers = new ResponseHeaderStore(new ResponseHeaderPolicy());
		$headers->setPolicy($this->application, [[
			'name' => 'X-Robots-Tag',
			'action' => 'set',
			'value' => 'noindex',
			'inheritance' => 'required',
		]]);
		$headers->setPolicy($this->site, [[
			'name' => 'X-Robots-Tag',
			'action' => 'set',
			'value' => 'nofollow',
		]]);
		$response = $this->controller(['ROLE_ADMIN'], [], $headers)->get($this->request('GET'));
		$data = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);

		self::assertSame('noindex', $data['responseHeaderRules']['application'][0]['value']);
		self::assertTrue($data['responseHeaderRules']['application'][0]['required']);
		self::assertSame('nofollow', $data['responseHeaderRules']['site'][0]['value']);
		self::assertSame([], $data['overrides']);
	}

	public function testSiteManagerPersistsOnlyCurrentSitePartialOverrides(): void
	{
		$this->entityManager->expects(self::once())->method('persist')->with($this->site);
		$this->entityManager->expects(self::once())->method('flush');

		$response = $this->controller([], ['ROLE_SITE_MANAGER'])->put($this->request('PUT', [
			'version' => 1,
			'overrides' => ['crawlers' => ['aiAccess' => 'disallow']],
		]));
		$data = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('disallow', $data['effective']['crawlers']['aiAccess']);
		self::assertSame('site', $data['sources']['crawlers']['aiAccess']);
		self::assertSame(1, $data['effective']['version']);
	}

	public function testSiteManagerCanDisableAndReenableTheDefaultBundledPlugin(): void
	{
		$this->entityManager->expects(self::exactly(2))->method('persist')->with($this->site);
		$this->entityManager->expects(self::exactly(2))->method('flush');
		$controller = $this->controller([], ['ROLE_SITE_MANAGER']);

		$disabled = $controller->setActivation($this->activationRequest(false));
		self::assertSame(200, $disabled->getStatusCode());
		self::assertSame(['enabled' => false], json_decode($disabled->getContent(), true, 64, JSON_THROW_ON_ERROR));
		self::assertFalse(in_array(ContentOptimizationSettings::PLUGIN_SLUG, $this->site->getEnabledPlugins(), true));

		$enabled = $controller->setActivation($this->activationRequest(true));
		self::assertSame(200, $enabled->getStatusCode());
		self::assertSame(['enabled' => true], json_decode($enabled->getContent(), true, 64, JSON_THROW_ON_ERROR));
		self::assertTrue(in_array(ContentOptimizationSettings::PLUGIN_SLUG, $this->site->getEnabledPlugins(), true));
	}

	public function testActivationPayloadMustContainOnlyAValidBoolean(): void
	{
		$response = $this->controller(['ROLE_ADMIN'])->setActivation($this->activationRequest('false'));

		self::assertSame(400, $response->getStatusCode());
	}

	public function testBodyCannotChooseAnOwner(): void
	{
		$this->entityManager->expects(self::never())->method('flush');

		$response = $this->controller(['ROLE_ADMIN'])->put($this->request('PUT', [
			'version' => 1,
			'applicationId' => 7,
			'overrides' => [],
		]));

		self::assertSame(400, $response->getStatusCode());
	}

	public function testPutRecordsAuditAndSetActivationRoutesThroughLifecycleHandler(): void
	{
		$audit = $this->createMock(AuditRecorder::class);
		$audit->expects(self::once())->method('record')->with(
			self::stringContains('content optimization settings'),
			'updated_configuration',
			'Site',
			42,
			self::anything(),
			self::anything(),
			self::anything(),
		);
		$lifecycle = $this->createMock(PluginLifecycleHandler::class);
		$lifecycle->expects(self::once())->method('deactivate');
		$lifecycle->expects(self::once())->method('activate');

		$controller = $this->controller([], ['ROLE_SITE_MANAGER'], null, $audit, $lifecycle);

		$putResponse = $controller->put($this->request('PUT', [
			'version' => 1,
			'overrides' => ['crawlers' => ['aiAccess' => 'disallow']],
		]));
		self::assertSame(200, $putResponse->getStatusCode());

		// Deactivate -> no-op deactivate -> activate
		$firstDisable = json_decode((string) $controller->setActivation($this->activationRequest(false))->getContent(), true, 64, JSON_THROW_ON_ERROR);
		$secondDisable = json_decode((string) $controller->setActivation($this->activationRequest(false))->getContent(), true, 64, JSON_THROW_ON_ERROR);
		$reEnable = json_decode((string) $controller->setActivation($this->activationRequest(true))->getContent(), true, 64, JSON_THROW_ON_ERROR);
		self::assertFalse($firstDisable['enabled'] ?? null);
		self::assertFalse($secondDisable['enabled'] ?? null);
		self::assertTrue($reEnable['enabled'] ?? null);
	}

	public function testRejectsUnauthenticatedNonSiteManagerOversizedInvalidJsonAndMissingApplication(): void
	{
		// Unauthenticated
		$security = $this->createMock(Security::class);
		$security->method('getUser')->willReturn(null);
		$roles = new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_SITE_MANAGER']]);
		$unauthController = new ContentOptimizationSettingsController(
			$security,
			$roles,
			new WorkflowAccessPolicy(new DigitalAssetAccessPolicy($this->sites, $roles), $roles),
			$this->applications,
			$this->sites,
			$this->entityManager,
			new ContentOptimizationSettings(),
		);
		try {
			$unauthController->get($this->request('GET'));
			self::fail('Unauthenticated user must be denied.');
		} catch (AccessDeniedHttpException) {
		}

		// Non-site-manager
		try {
			$this->controller([], ['ROLE_USER'])->get($this->request('GET'));
			self::fail('Non-site-manager must be denied.');
		} catch (AccessDeniedHttpException) {
		}

		// Oversized body
		$oversized = Request::create('/', 'PUT', [], [], [], [], str_repeat('a', 70000));
		$oversized->attributes->set('siteId', '42');
		self::assertSame(400, $this->controller(['ROLE_ADMIN'])->put($oversized)->getStatusCode());

		// Invalid JSON
		$invalidJson = Request::create('/', 'PUT', [], [], [], [], '{broken');
		$invalidJson->attributes->set('siteId', '42');
		self::assertSame(400, $this->controller(['ROLE_ADMIN'])->put($invalidJson)->getStatusCode());

		// Missing application
		$missingApp = Request::create('/', 'GET');
		$missingApp->attributes->set('applicationId', '999');
		$this->expectException(NotFoundHttpException::class);
		$this->controller(['ROLE_ADMIN'])->get($missingApp);
	}

	/**
	 * @param list<string> $globalRoles
	 * @param list<string> $siteRoles
	 */
	private function controller(
		array $globalRoles = [],
		array $siteRoles = [],
		?ResponseHeaderStore $responseHeaders = null,
		?AuditRecorder $auditRecorder = null,
		?PluginLifecycleHandler $lifecycle = null,
	): ContentOptimizationSettingsController
	{
		$user = $this->createMock(User::class);
		$user->method('getGlobalRoles')->willReturn(UserRoleManager::convertStringsToEnums(array_combine($globalRoles, $globalRoles) ?: []));
		$user->method('getRegistrationRoles')->willReturn(UserRoleManager::convertStringsToEnums(array_combine($siteRoles, $siteRoles) ?: []));
		$security = $this->createMock(Security::class);
		$security->method('getUser')->willReturn($user);
		$roles = new RoleHierarchy(['ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'], 'ROLE_ADMIN' => ['ROLE_SITE_MANAGER']]);
		$access = new WorkflowAccessPolicy(new DigitalAssetAccessPolicy($this->sites, $roles), $roles);

		return new ContentOptimizationSettingsController(
			$security,
			$roles,
			$access,
			$this->applications,
			$this->sites,
			$this->entityManager,
			new ContentOptimizationSettings(),
			$responseHeaders,
			$auditRecorder,
			$lifecycle,
		);
	}

	/** @param array<string,mixed> $body */
	private function request(string $method, array $body = [], bool $application = false, string $id = '42'): Request
	{
		$content = 'GET' === $method ? '' : json_encode($body, JSON_THROW_ON_ERROR);
		$request = Request::create('/', $method, [], [], [], [], $content);
		$request->attributes->set($application ? 'applicationId' : 'siteId', $application ? '7' : $id);

		return $request;
	}

	private function activationRequest(mixed $enabled): Request
	{
		$request = Request::create(
			'/api/site/42/content-optimization/activation',
			'PUT',
			[],
			[],
			[],
			[],
			json_encode(['enabled' => $enabled], JSON_THROW_ON_ERROR),
		);
		$request->attributes->set('siteId', '42');

		return $request;
	}
}
