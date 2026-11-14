<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Controller\Api\Admin\ResponseHeadersController;
use iikiti\CMS\Controller\Api\Internal\CspReportController;
use iikiti\CMS\Event\Subscriber\SecurityHeadersSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SecurityHeadersWiringTest extends KernelTestCase
{
	public function testResponseHeadersControllerIsRegistered(): void
	{
		self::bootKernel();
		self::assertInstanceOf(ResponseHeadersController::class, self::getContainer()->get(ResponseHeadersController::class));
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
			'api_admin_application_headers_get' => '/api/admin/applications/{applicationId}/headers',
			'api_admin_application_headers_put' => '/api/admin/applications/{applicationId}/headers',
			'api_admin_application_headers_preview' => '/api/admin/applications/{applicationId}/headers/preview',
			'api_site_headers_get' => '/api/site/{siteId}/headers',
			'api_site_headers_put' => '/api/site/{siteId}/headers',
			'api_site_headers_preview' => '/api/site/{siteId}/headers/preview',
			'api_internal_csp_reports' => '/api/internal/csp-reports',
		];

		foreach ($expectedRoutes as $name => $path) {
			$route = $routeCollection->get($name);
			self::assertNotNull($route, "Route {$name} should exist");
			self::assertSame($path, $route->getPath());
		}
		self::assertNull($routeCollection->get('api_admin_site_security_headers_get'));
		self::assertNull($routeCollection->get('api_admin_site_security_headers_put'));
		self::assertNull($routeCollection->get('api_admin_site_security_headers_test'));
	}
}
