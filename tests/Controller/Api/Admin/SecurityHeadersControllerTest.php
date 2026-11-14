<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\Api\Admin\SecurityHeadersController;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\SecurityHeaderPolicy;
use iikiti\CMS\Service\SecurityHeaderStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
final class SecurityHeadersControllerTest extends KernelTestCase
{
	private function makeController(SecurityHeaderStore $store, SecurityHeaderPolicy $policy, ?EntityManagerInterface $em = null, string $envMode = 'default'): SecurityHeadersController
	{
		$security = $this->createMock(Security::class);
		$security->method('isGranted')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		if (null !== $em) {
			$container->expects(self::any())->method('get')->with(EntityManagerInterface::class)->willReturn($em);
		}

		return new SecurityHeadersController($security, $container, $store, $policy, $envMode);
	}

	private function pushSite(string $siteId): Site
	{
		$stackRef = new \ReflectionProperty(SiteRegistry::class, 'siteStack');
		$stackRef->setValue(null, new \SplStack());

		$site = new Site();
		$idRef = new \ReflectionProperty(Site::class, 'id');
		$idRef->setValue($site, $siteId);

		SiteRegistry::pushSite($site);

		return $site;
	}

	public function testGetReturnsCurrentPolicy(): void
	{
		self::bootKernel();

		$site = $this->pushSite('42');

		$store = new SecurityHeaderStore();
		$store->setPolicy($site, [
			['name' => SecurityHeaderPolicy::HEADER_FRAME_OPTIONS, 'mode' => 'on', 'value' => 'DENY'],
		]);
		$policy = new SecurityHeaderPolicy('prod');
		$controller = $this->makeController($store, $policy);

		$request = Request::create('/api/admin/sites/42/security/headers', 'GET');
		$request->attributes->set('siteId', '42');

		$response = $controller->get($request);

		self::assertInstanceOf(JsonResponse::class, $response);
		$data = json_decode($response->getContent(), true);
		self::assertSame('42', $data['siteId']);
		self::assertSame(2, $data['version']);
		self::assertCount(1, $data['headers']);
		self::assertSame('DENY', $data['headers'][0]['value']);
	}

	public function testGet404WhenSiteMismatch(): void
	{
		self::bootKernel();

		$this->pushSite('42');

		$store = new SecurityHeaderStore();
		$policy = new SecurityHeaderPolicy('prod');
		$controller = $this->makeController($store, $policy);
		$request = Request::create('/api/admin/sites/99/security/headers', 'GET');
		$request->attributes->set('siteId', '99');

		$this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
		$controller->get($request);
	}

	public function testPutReplacesPolicyAndBumpsVersion(): void
	{
		self::bootKernel();

		$site = $this->pushSite('42');

		$store = new SecurityHeaderStore();
		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::once())->method('persist')->with($site);
		$em->expects(self::once())->method('flush');
		$policy = new SecurityHeaderPolicy('prod');
		$controller = $this->makeController($store, $policy, $em);

		$request = Request::create('/api/admin/sites/42/security/headers', 'PUT', [], [], [], [], json_encode([
			'headers' => [
				['name' => SecurityHeaderPolicy::HEADER_FRAME_OPTIONS, 'mode' => 'on', 'value' => 'SAMEORIGIN'],
			],
		]));
		$request->attributes->set('siteId', '42');
		$request->headers->set('Content-Type', 'application/json');

		$response = $controller->put($request);

		self::assertInstanceOf(JsonResponse::class, $response);
		$data = json_decode($response->getContent(), true);
		self::assertSame(2, $data['version']);
	}

	public function testPut400OnMalformedBody(): void
	{
		self::bootKernel();

		$this->pushSite('42');

		$store = new SecurityHeaderStore();
		$policy = new SecurityHeaderPolicy('prod');
		$controller = $this->makeController($store, $policy);
		$request = Request::create('/api/admin/sites/42/security/headers', 'PUT', [], [], [], [], json_encode(['foo' => 'bar']));
		$request->attributes->set('siteId', '42');
		$request->headers->set('Content-Type', 'application/json');

		$response = $controller->put($request);

		self::assertSame(400, $response->getStatusCode());
	}

	public function testTestEndpointReturnsSampleResponseWithHeaders(): void
	{
		self::bootKernel();

		$this->pushSite('42');

		$store = new SecurityHeaderStore();
		$policy = new SecurityHeaderPolicy('prod');
		$controller = $this->makeController($store, $policy);
		$request = Request::create('/api/admin/sites/42/security/headers/test', 'POST');
		$request->attributes->set('siteId', '42');
		$request->server->set('HTTPS', 'on');

		$response = $controller->test($request);

		self::assertInstanceOf(Response::class, $response);
		self::assertSame(200, $response->getStatusCode());
		self::assertSame('DENY', $response->headers->get(SecurityHeaderPolicy::HEADER_FRAME_OPTIONS));
		self::assertNotNull($response->headers->get('X-Iikiti-CSP-Nonce'));
	}
}
