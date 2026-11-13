<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Profiler;

use Doctrine\DBAL\ParameterType;
use iikiti\CMS\Profiler\ProfilerQueryDiagnostics;
use iikiti\CMS\Profiler\ProfilerQueryDiagnosticsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;

final class ProfilerQueryDiagnosticsTest extends TestCase
{
	public function testSignsOnlyApprovedPageRenderRequests(): void
	{
		$diagnostics = new ProfilerQueryDiagnostics('shared-dev-secret');
		$path = '/api/public/render/pages/about/fragments/widget';
		$headers = $diagnostics->requestHeaders('GET', $path);
		$request = Request::create('https://api.example.test'.$path);
		$request->headers->add($headers);

		self::assertCount(2, $headers);
		self::assertTrue($diagnostics->isTrustedRequest($request));
		self::assertSame([], $diagnostics->requestHeaders('GET', '/api/admin/users'));
		self::assertNotSame([], $diagnostics->requestHeaders('POST', '/api/auth/session-token'));

		$changedPath = Request::create('https://api.example.test/api/public/render/pages/other/fragments/widget');
		$changedPath->headers->add($headers);
		self::assertFalse($diagnostics->isTrustedRequest($changedPath));
	}

	public function testSnapshotBoundsAndNormalizesDoctrineQueryData(): void
	{
		$diagnostics = new ProfilerQueryDiagnostics('secret');
		$query = new Query('SELECT * FROM pages WHERE id = ?', null);
		$query->setValue(1, '7', ParameterType::STRING);
		$query->start();
		$query->stop();
		$holder = new DebugDataHolder();
		$holder->addQuery('default', $query);

		$snapshot = $diagnostics->snapshot($holder->getData(), 'Page "/" | Template "Landing" */');

		self::assertCount(1, $snapshot['queries']);
		self::assertSame('default', $snapshot['queries'][0]['connection']);
		self::assertSame('SELECT * FROM pages WHERE id = ?', $snapshot['queries'][0]['sql']);
		self::assertSame([0 => '7'], $snapshot['queries'][0]['params']);
		self::assertGreaterThanOrEqual(0.0, $snapshot['queries'][0]['execution_ms']);
		self::assertSame('Page "/" | Template "Landing" * /', $snapshot['context']);

		$manyQueries = array_fill(0, 51, [
			'sql' => 'SELECT 1',
			'params' => [],
			'types' => [],
			'executionMS' => 0.001,
		]);
		$bounded = $diagnostics->snapshot(['default' => $manyQueries]);
		self::assertCount(50, $bounded['queries']);
		self::assertSame(1, $bounded['omitted']);
	}

	public function testResponseSubscriberRequiresSignedProfilerOptIn(): void
	{
		$diagnostics = new ProfilerQueryDiagnostics('shared-dev-secret');
		$debugData = new DebugDataHolder();
		$query = new Query('SELECT 1', null);
		$query->start();
		$query->stop();
		$debugData->addQuery('default', $query);
		$profiler = $this->createStub(Profiler::class);
		$profiler->method('isEnabled')->willReturn(true);
		$subscriber = new ProfilerQueryDiagnosticsSubscriber($debugData, $diagnostics, $profiler);
		$path = '/api/public/render/home/manifest';
		$request = Request::create('https://api.example.test'.$path);
		$request->headers->add($diagnostics->requestHeaders('GET', $path));
		$request->attributes->set(ProfilerQueryDiagnostics::RENDER_CONTEXT_ATTRIBUTE, 'Page "/" | Template "Landing"');
		$response = new JsonResponse(['html' => '<main></main>']);

		$subscriber->onKernelResponse(new ResponseEvent(
			$this->createStub(HttpKernelInterface::class),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$response,
		));

		$body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayHasKey(ProfilerQueryDiagnostics::QUERY_DATA_KEY, $body);
		self::assertSame('SELECT 1', $body[ProfilerQueryDiagnostics::QUERY_DATA_KEY]['queries'][0]['sql']);
		self::assertSame('Page "/" | Template "Landing"', $body[ProfilerQueryDiagnostics::QUERY_DATA_KEY]['context']);

		$disabledProfiler = $this->createStub(Profiler::class);
		$disabledProfiler->method('isEnabled')->willReturn(false);
		$disabledSubscriber = new ProfilerQueryDiagnosticsSubscriber($debugData, $diagnostics, $disabledProfiler);
		$disabled = new JsonResponse(['html' => '<main></main>']);
		$disabledSubscriber->onKernelResponse(new ResponseEvent(
			$this->createStub(HttpKernelInterface::class),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$disabled,
		));
		self::assertArrayNotHasKey(ProfilerQueryDiagnostics::QUERY_DATA_KEY, json_decode((string) $disabled->getContent(), true, 512, JSON_THROW_ON_ERROR));

		$unsigned = new JsonResponse(['html' => '<main></main>']);
		$subscriber->onKernelResponse(new ResponseEvent(
			$this->createStub(HttpKernelInterface::class),
			Request::create('https://api.example.test'.$path),
			HttpKernelInterface::MAIN_REQUEST,
			$unsigned,
		));
		self::assertArrayNotHasKey(ProfilerQueryDiagnostics::QUERY_DATA_KEY, json_decode((string) $unsigned->getContent(), true, 512, JSON_THROW_ON_ERROR));
	}
}
