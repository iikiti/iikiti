<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Response\Composition;

use Dom\HTMLDocument;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Async\Promise;
use iikiti\CMS\Response\Composition\ControllerResponseComposer;
use iikiti\CMS\Response\Composition\ControllerResponsePlan;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\ResponseFragment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ControllerResponseComposerTest extends TestCase
{
	public function testControllerHtmlTasksComposeIntoTheDocumentInSlotOrder(): void
	{
		$http = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse($url));
		$composer = new ControllerResponseComposer(new FiberTaskRunner($http));
		$document = HTMLDocument::createFromString('<!DOCTYPE html><html><body><header><template id="header-slot"></template></header><main id="main"><template id="main-slot"></template></main></body></html>');
		$plan = ControllerResponsePlan::html(
			$document,
			[
				'main' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html(
					'#main-slot',
					'<section>Main '.$client->request('GET', 'https://example.test/main')->getContent().'</section>',
					20,
				)),
				'header' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html(
					'#header-slot',
					'<strong>Header '.$client->request('GET', 'https://example.test/header')->getContent().'</strong>',
					10,
				)),
			],
			status: 202,
		);

		$response = $composer->compose($plan);

		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertSame($document, $response->document());
		self::assertSame(202, $response->getStatusCode());
		self::assertStringContainsString('<strong>Header https://example.test/header</strong>', $response->getContent());
		self::assertStringContainsString('<section>Main https://example.test/main</section>', $response->getContent());
		self::assertStringNotContainsString('id="header-slot"', $response->getContent());
		self::assertStringNotContainsString('id="main-slot"', $response->getContent());
	}

	public function testControllerJsonObjectPlanUsesTheExistingJsonResponse(): void
	{
		$composer = new ControllerResponseComposer(new FiberTaskRunner(new MockHttpClient()));
		$response = $composer->compose(ControllerResponsePlan::jsonObject([
			'context' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::jsonObject(['id' => 7], order: 20)),
			'permissions' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::jsonObject(['canEdit' => false], order: 10)),
		]));

		self::assertInstanceOf(JsonResponse::class, $response);
		self::assertSame(['canEdit' => false, 'id' => 7], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
	}

	public function testControllerCanReturnAnExistingDomDocumentFragment(): void
	{
		$fragmentDocument = HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
		$fragment = $fragmentDocument->createDocumentFragment();
		$article = $fragmentDocument->createElement('article');
		$article->textContent = 'DOM fragment';
		$fragment->append($article);
		$composer = new ControllerResponseComposer(new FiberTaskRunner(new MockHttpClient()));
		$response = $composer->compose(ControllerResponsePlan::html(
			'<!DOCTYPE html><html><body><main><template id="content"></template></main></body></html>',
			['content' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html('#content', $fragment))],
		));

		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertNotNull($response->document()->querySelector('main article'));
		self::assertStringContainsString('<article>DOM fragment</article>', $response->getContent());
	}

	public function testControllerTextPlanUsesAnOrderedBufferAndPreservesCustomType(): void
	{
		$composer = new ControllerResponseComposer(new FiberTaskRunner(new MockHttpClient()));
		$response = $composer->compose(ControllerResponsePlan::text([
			'last' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::text('tail', order: 20)),
			'first' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::text('head', order: 10)),
		], contentType: 'application/octet-stream'));

		self::assertSame('headtail', $response->getContent());
		self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
	}
}
