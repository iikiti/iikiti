<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Controller\Api\Admin\SecurityHeadersController;
use iikiti\CMS\Controller\Api\Internal\CspReportController;
use iikiti\CMS\Event\Subscriber\SecurityHeadersSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SecurityHeadersWiringTest extends KernelTestCase
{
	public function testSecurityHeadersControllerIsRegistered(): void
	{
		self::bootKernel();
		self::assertInstanceOf(SecurityHeadersController::class, self::getContainer()->get(SecurityHeadersController::class));
	}

	public function testCspReportControllerIsRegistered(): void
	{
		self::bootKernel();
		self::assertInstanceOf(CspReportController::class, self::getContainer()->get(CspReportController::class));
	}

	public function testSecurityHeadersSubscriberIsRegistered(): void
	{
		self::bootKernel();
		self::assertInstanceOf(SecurityHeadersSubscriber::class, self::getContainer()->get(SecurityHeadersSubscriber::class));
	}

	public function testSecurityHeadersRoutesExist(): void
	{
		self::bootKernel();

		$router = self::getContainer()->get('router');
		$routeCollection = $router->getRouteCollection();

		$expectedRoutes = [
			'api_admin_site_security_headers_get' => '/api/admin/sites/{siteId}/security/headers',
			'api_admin_site_security_headers_put' => '/api/admin/sites/{siteId}/security/headers',
			'api_admin_site_security_headers_test' => '/api/admin/sites/{siteId}/security/headers/test',
			'api_internal_csp_reports' => '/api/internal/csp-reports',
		];

		foreach ($expectedRoutes as $name => $path) {
			$route = $routeCollection->get($name);
			self::assertNotNull($route, "Route {$name} should exist");
			self::assertSame($path, $route->getPath());
		}
	}
}
