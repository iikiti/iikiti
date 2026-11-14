<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\Api\Admin\ResponseHeadersController;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

#[AllowMockObjectsWithoutExpectations]
final class ResponseHeadersControllerTest extends KernelTestCase
{
	private Application $application;
	private Site $site;
	private ResponseHeaderStore $store;
	private ApplicationRepository $applications;
	private SiteRepository $sites;
	private EntityManagerInterface&MockObject $em;

	protected function setUp(): void
	{
		self::bootKernel();
		$this->application = new Application();
		$this->site = new Site();
		(new \ReflectionProperty(Site::class, 'id'))->setValue($this->site, 42);
		(new \ReflectionProperty(Application::class, 'id'))->setValue($this->application, 7);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		SiteRegistry::pushSite($this->site);
		$this->store = new ResponseHeaderStore(new ResponseHeaderPolicy());
		$this->applications = $this->createMock(ApplicationRepository::class);
		$this->applications->method('find')->willReturnCallback(fn ($id) => 7 === (int) $id ? $this->application : null);
		$this->sites = $this->createMock(SiteRepository::class);
		$this->sites->method('getApplicationBySite')->willReturn($this->application);
		$this->em = $this->createMock(EntityManagerInterface::class);
	}

	/** @param list<string> $global
	 * @param list<string> $site
	 */
	private function controller(array $global = [], array $site = []): ResponseHeadersController
	{
		$user = $this->createMock(User::class);
		$registration = array_merge($global, $site);
		$user->method('getGlobalRoles')->willReturn(UserRoleManager::convertStringsToEnums(array_combine($global, $global)));
		$user->method('getRegistrationRoles')->willReturn(UserRoleManager::convertStringsToEnums(array_combine($registration, $registration)));
		$security = $this->createMock(Security::class);
		$security->method('getUser')->willReturn($user);
		$hierarchy = new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_SITE_MANAGER'], 'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN']]);
		$access = new WorkflowAccessPolicy(new DigitalAssetAccessPolicy($this->sites, $hierarchy), $hierarchy);

		return new ResponseHeadersController($security, $hierarchy, $access, $this->applications, $this->sites, $this->em, $this->store, new ResponseHeaderPolicy());
	}

	/** @param array<string,mixed> $body */
	private function request(string $method, array $body = [], bool $application = false, string $id = '42'): Request
	{
		$request = Request::create('/', $method, [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
		$request->attributes->set($application ? 'applicationId' : 'siteId', $application ? '7' : $id);

		return $request;
	}

	public function testApplicationRequiresGlobalRoleEvenForSiteAdmin(): void
	{
		$this->expectException(AccessDeniedHttpException::class);
		$this->controller([], ['ROLE_ADMIN'])->get($this->request('GET', [], true));
	}

	public function testApplicationGlobalAdminAndMissingApplication(): void
	{
		$controller = $this->controller(['ROLE_ADMIN']);
		self::assertSame(1, json_decode($controller->get($this->request('GET', [], true))->getContent(), true)['version']);
		$request = $this->request('GET', [], true);
		$request->attributes->set('applicationId', '99');
		$this->expectException(NotFoundHttpException::class);
		$controller->get($request);
	}

	public function testSiteManagerRoundTripAndValidation(): void
	{
		$controller = $this->controller([], ['ROLE_SITE_MANAGER']);
		$this->em->expects(self::once())->method('flush');
		$rules = [['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex']];
		$result = $controller->put($this->request('PUT', ['rules' => $rules]));
		$data = json_decode($result->getContent(), true);
		self::assertSame(2, $data['version']);
		self::assertSame('x-robots-tag', $data['rules'][0]['name']);
		self::assertSame($data, json_decode($controller->get($this->request('GET'))->getContent(), true));
		$bad = $controller->put($this->request('PUT', ['rules' => [['name' => 'Content-Length', 'action' => 'set', 'value' => '1']]]));
		self::assertSame(400, $bad->getStatusCode());
		self::assertStringContainsString('header', json_decode($bad->getContent(), true)['error']);
	}

	public function testWrongSiteAndInsufficientRole(): void
	{
		try {
			$this->controller([], ['ROLE_SITE_MANAGER'])->get($this->request('GET', [], false, '99'));
			self::fail('Expected site mismatch');
		} catch (NotFoundHttpException) {
		}
		$this->expectException(AccessDeniedHttpException::class);
		$this->controller([], ['ROLE_EDITOR'])->get($this->request('GET'));
	}

	public function testPreviewUsesHypotheticalContextWithoutWriting(): void
	{
		$controller = $this->controller([], ['ROLE_SITE_MANAGER']);
		$this->em->expects(self::never())->method('flush');
		$response = $controller->preview($this->request('POST', [
			'rules' => [['name' => 'X-Test', 'action' => 'set', 'value' => 'yes', 'conditions' => ['field' => 'status', 'operator' => 'equals', 'value' => 404]]],
			'request' => ['scheme' => 'http', 'method' => 'GET', 'path' => '/missing'],
			'status' => 404,
		]));
		self::assertSame('yes', json_decode($response->getContent(), true)['rules']['x-test']['value']);
		self::assertSame(1, $this->store->getPolicy($this->site)['version']);
		$bad = $controller->preview($this->request('POST', ['request' => ['headers' => ['Authorization' => 'secret']]]));
		self::assertSame(400, $bad->getStatusCode());
	}

	public function testMalformedJsonAndObjectInsteadOfRulesListAreRejected(): void
	{
		$controller = $this->controller(['ROLE_ADMIN']);
		foreach (['{', '{"rules":{}}', '{"rules":[],"request":[]}'] as $json) {
			$request = Request::create('/', 'PUT', [], [], [], [], $json);
			$request->attributes->set('applicationId', '7');
			$response = $controller->put($request);
			self::assertSame(400, $response->getStatusCode());
			self::assertNotEmpty(json_decode($response->getContent(), true)['error']);
		}
	}

	public function testSitePreviewHonorsRequiredApplicationRule(): void
	{
		$this->store->setPolicy($this->application, [['name' => 'X-Test', 'action' => 'set', 'value' => 'required', 'inheritance' => 'required']]);
		$response = $this->controller([], ['ROLE_SITE_MANAGER'])->preview($this->request('POST', [
			'rules' => [['name' => 'X-Test', 'action' => 'remove']],
		]));
		self::assertSame('required', json_decode($response->getContent(), true)['rules']['x-test']['value']);
	}

	public function testGlobalAdminCanAccessSiteAndEmptySiteContextIsNotFound(): void
	{
		$controller = $this->controller(['ROLE_ADMIN']);
		self::assertSame(200, $controller->get($this->request('GET'))->getStatusCode());
		SiteRegistry::popSite();
		$this->expectException(NotFoundHttpException::class);
		$controller->get($this->request('GET'));
	}
}
