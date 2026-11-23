<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Mcp\ApiTools;
use iikiti\CMS\Mcp\McpApiEndpoint;
use iikiti\CMS\Mcp\McpApiEndpointCatalog;
use iikiti\CMS\Mcp\McpRuntimeGuardSubscriber;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiTokenManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class McpApiRegistryWiringTest extends KernelTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
	}

	public function testAllCallerFacingApiRoutesAppearBehindOnePaginatedCatalog(): void
	{
		static::bootKernel();
		$container = static::getContainer();
		$catalog = $container->get(McpApiEndpointCatalog::class);
		$tools = $container->get(ApiTools::class);

		$page = $tools->apiList(limit: 200);
		self::assertFalse($page->isError);
		self::assertGreaterThan(50, $page->structuredContent['total']);
		self::assertContains('iikiti.core.read_users.get.admin_users_list', array_column($page->structuredContent['items'], 'id'));
		self::assertContains('iikiti.core.write_jobs.post.api_admin_batch_schedules_create', array_column($page->structuredContent['items'], 'id'));
		$apiIds = array_map(static fn (McpApiEndpoint $endpoint): string => $endpoint->id, $catalog->all());
		self::assertGreaterThan(50, count($apiIds));
		foreach (['objects', 'users', 'sites', 'groups', 'roles', 'activity', 'workflows', 'assets', 'templates', 'shells', 'plugins', 'search', 'security'] as $feature) {
			$operations = ['read_'];
			$hasApi = false;
			foreach ($apiIds as $apiId) {
				foreach ($operations as $operation) {
					if (str_starts_with($apiId, 'iikiti.core.'.$operation.$feature.'.')) {
						$hasApi = true;
						break 2;
					}
				}
			}
			self::assertTrue($hasApi, 'Expected API coverage for '.$feature);
		}
		self::assertNotEmpty(array_filter(
			$apiIds,
			static fn (string $apiId): bool => str_starts_with($apiId, 'iikiti.plugin.iikiti-forms.read_forms.'),
		), 'Expected plugin API coverage for forms');
		self::assertContains('iikiti.core.write_settings.post.api_editor_settings_save', $apiIds);

		$userList = $catalog->get('iikiti.core.read_users.get.admin_users_list');
		self::assertNotNull($userList);
		self::assertSame('GET', $userList->method);
		self::assertNotEmpty($catalog->getInputSchema($userList));
		$description = $tools->apiDescribe($userList->id);
		self::assertFalse($description->isError);
		self::assertNotEmpty($description->structuredContent['description']);
		self::assertIsArray($description->structuredContent['outputSchema']);
	}

	public function testMcpHttpEndpointGuardHonorsTheConfiguredSetting(): void
	{
		static::bootKernel();
		new SiteRegistry(new RequestStack(), static::getContainer()->get(ManagerRegistry::class), static::getContainer()->get(GuestApiTokenManager::class), 'api');
		$kernel = static::getContainer()->get('kernel');
		$subscriber = static::getContainer()->get(McpRuntimeGuardSubscriber::class);
		$event = new RequestEvent($kernel, Request::create('/api/mcp'), HttpKernelInterface::MAIN_REQUEST);
		if (static::getContainer()->getParameter('mcp_http_enabled')) {
			self::assertNull($subscriber($event));
		} else {
			$this->expectException(NotFoundHttpException::class);
			$subscriber($event);
		}
	}
}
