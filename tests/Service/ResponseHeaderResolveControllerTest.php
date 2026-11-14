<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Controller\Api\Internal\ResponseHeaderResolveController;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Event\Subscriber\SecurityHeadersSubscriber;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\RemoteResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderApplicator;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Service\SecurityHeaderPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ResponseHeaderResolveControllerTest extends TestCase
{
	public function testInternalResponsesReceiveLocalPolicyAndRemainNoStore(): void
	{
		$policy = new ResponseHeaderPolicy();
		$store = new ResponseHeaderStore($policy);
		$app = new Application();
		$store->setPolicy($app, [['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex']]);
		$sites = $this->createStub(SiteRepository::class);
		$sites->method('findByDomain')->willReturn([]);
		$apps = $this->createStub(ApplicationRepository::class);
		$apps->method('find')->willReturn($app);
		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('getRepository')->willReturnCallback(static fn (string $class) => Site::class === $class ? $sites : $apps);
		$resolver = new ResponseHeaderResolver($em, $store, $policy, 7);
		$remote = new RemoteResponseHeaderResolver(new BusinessApiClient(new MockHttpClient(), 'http://api.internal', 'test'), 'secret');
		$subscriber = new SecurityHeadersSubscriber($resolver, $remote, new ResponseHeaderApplicator(new SecurityHeaderPolicy('prod'), 'default'), new NullLogger(), 'api');
		foreach ([200, 401] as $status) {
			$response = new Response('', $status, ['Cache-Control' => 'no-store']);
			$event = new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('http://api.test/api/internal/response-headers/resolve'), HttpKernelInterface::MAIN_REQUEST, $response);
			$subscriber->onKernelResponse($event);
			self::assertSame($status, $event->getResponse()->getStatusCode());
			self::assertSame('noindex', $event->getResponse()->headers->get('X-Robots-Tag'));
			self::assertStringContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
		}
	}

	public function testSignedRequestEvaluatesOriginalContextAndFallbackApplication(): void
	{
		$policy = new ResponseHeaderPolicy();
		$store = new ResponseHeaderStore($policy);
		$app = new Application();
		$store->setPolicy($app, [[
			'name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex',
			'conditions' => ['all' => [
				['field' => 'status', 'operator' => 'equals', 'value' => 404],
				['field' => 'header', 'name' => 'x-device', 'operator' => 'equals', 'value' => 'mobile'],
			]],
		]]);
		$sites = $this->createMock(SiteRepository::class);
		$sites->expects(self::once())->method('findByDomain')->with('unknown.test')->willReturn([]);
		$apps = $this->createMock(ApplicationRepository::class);
		$apps->expects(self::once())->method('find')->with(7)->willReturn($app);
		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::exactly(2))->method('getRepository')->willReturnCallback(static fn (string $class) => Site::class === $class ? $sites : $apps);
		$controller = new ResponseHeaderResolveController(new ResponseHeaderResolver($em, $store, $policy, 7), 'secret', ['unknown.test']);
		$payload = ['host' => 'unknown.test', 'scheme' => 'https', 'method' => 'GET', 'path' => '/missing', 'status' => 404, 'headers' => ['x-device' => 'mobile'], 'time' => time()];
		$signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'secret');
		$request = Request::create('/api/internal/response-headers/resolve', 'POST', content: json_encode(['payload' => $payload, 'signature' => $signature], JSON_THROW_ON_ERROR));
		$response = $controller($request);
		self::assertSame(200, $response->getStatusCode());
		$data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(['name' => 'x-robots-tag', 'action' => 'set', 'value' => 'noindex'], $data['rules']['x-robots-tag']);
		self::assertSame(['x-device'], $data['vary']);
		self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
	}

	public function testRejectsHostsOutsideConsumerAllowList(): void
	{
		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::never())->method('getRepository');
		$policy = new ResponseHeaderPolicy();
		$controller = new ResponseHeaderResolveController(new ResponseHeaderResolver($em, new ResponseHeaderStore($policy), $policy, 0), 'secret', ['www.example.test']);
		$payload = ['host' => 'other.test', 'scheme' => 'https', 'method' => 'GET', 'path' => '/', 'status' => 200, 'headers' => [], 'time' => time()];
		$signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'secret');
		$request = Request::create('/api/internal/response-headers/resolve', 'POST', content: json_encode(['payload' => $payload, 'signature' => $signature], JSON_THROW_ON_ERROR));
		self::assertSame(400, $controller($request)->getStatusCode());
	}

	public function testVaryIncludesHeadersFromNonmatchingAndNestedRules(): void
	{
		$app = [['conditions' => ['all' => [['field' => 'header', 'name' => 'x-device'], ['not' => ['field' => 'header', 'name' => 'x-region']]]]]];
		$site = [['conditions' => ['field' => 'header', 'name' => 'x-device']]];
		self::assertSame(['x-device', 'x-region'], ResponseHeaderResolver::varyForRules($app, $site));
	}

	public function testRejectsUnsignedAndExpiredRequestsBeforeDatabaseAccess(): void
	{
		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::never())->method('getRepository');
		$policy = new ResponseHeaderPolicy();
		$controller = new ResponseHeaderResolveController(new ResponseHeaderResolver($em, new ResponseHeaderStore($policy), $policy, 0), 'secret', ['unknown.test']);
		$payload = ['time' => time() - 120];
		foreach (['invalid', hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'secret')] as $signature) {
			$request = Request::create('/api/internal/response-headers/resolve', 'POST', content: json_encode(['payload' => $payload, 'signature' => $signature], JSON_THROW_ON_ERROR));
			self::assertSame(401, $controller($request)->getStatusCode());
		}
	}
}
