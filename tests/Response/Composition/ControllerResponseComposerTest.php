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
use iikiti\CMS\Tests\Support\Async\ControllableHttpClient;
use iikiti\CMS\Tests\Support\Async\ControllableResponse;
use Jimbo2150\CssIndexedDom\IndexedDocumentFragment;
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

	public function testControllerCanReturnAnIndexedDocumentFragment(): void
	{
		$indexedFragment = IndexedDocumentFragment::createFromString('<article class="card">Indexed fragment</article>');
		$card = $indexedFragment->querySelector('.card');
		self::assertNotNull($card);
		$indexedFragment->addClass($card, 'highlighted');

		$composer = new ControllerResponseComposer(new FiberTaskRunner(new MockHttpClient()));
		$response = $composer->compose(ControllerResponsePlan::html(
			'<!DOCTYPE html><html><body><main><template id="content"></template></main></body></html>',
			['content' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html('#content', $indexedFragment))],
		));

		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertNotNull($response->indexedDocument()->querySelector('main article.card.highlighted'));
		self::assertStringContainsString('<article class="card highlighted">Indexed fragment</article>', $response->getContent());
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

	public function testBlockedHtmlFragmentAllowsSiblingFragmentToRenderFirstWhilePreservingDeclaredOrder(): void
	{
		$secondCompleted = false;
		$http = new ControllableHttpClient();
		$http->whenUrlContains('/slow-first', static function () use (&$secondCompleted): ControllableResponse {
			return ControllableResponse::ok('First', key: 'slow-first')
				->holdUntil(static function () use (&$secondCompleted): bool {
					return $secondCompleted;
				});
		});
		$http->whenUrlContains('/fast-second', static function () use (&$secondCompleted): ControllableResponse {
			return ControllableResponse::ok('Second', key: 'fast-second')
				->onComplete(static function () use (&$secondCompleted): void {
					$secondCompleted = true;
				});
		});

		$composer = new ControllerResponseComposer(new FiberTaskRunner($http));
		$response = $composer->compose(ControllerResponsePlan::html(
			'<!DOCTYPE html><html><body><main><template id="slot"></template></main></body></html>',
			[
				'first' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html(
					'#slot',
					'<p>'.$client->request('GET', 'https://example.test/slow-first')->getContent().'</p>',
					order: 10,
				)),
				'second' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html(
					'#slot',
					'<p>'.$client->request('GET', 'https://example.test/fast-second')->getContent().'</p>',
					order: 20,
				)),
			],
		));

		$html = (string) $response->getContent();
		self::assertStringContainsString('<p>First</p><p>Second</p>', $html);
		self::assertLessThan(
			array_search('complete:slow-first', $http->getEvents(), true),
			array_search('complete:fast-second', $http->getEvents(), true),
		);
	}

	public function testBlockedJsonAndTextFragmentsPreserveDeclaredOrderDespiteOutOfOrderCompletion(): void
	{
		$fastDone = false;
		$http = new ControllableHttpClient();
		$http->whenUrlContains('/slow', static function () use (&$fastDone): ControllableResponse {
			return ControllableResponse::ok('slow', key: 'slow')
				->holdUntil(static function () use (&$fastDone): bool {
					return $fastDone;
				});
		});
		$http->whenUrlContains('/fast', static function () use (&$fastDone): ControllableResponse {
			return ControllableResponse::ok('fast', key: 'fast')
				->onComplete(static function () use (&$fastDone): void {
					$fastDone = true;
				});
		});
		$composer = new ControllerResponseComposer(new FiberTaskRunner($http));

		$jsonObject = $composer->compose(ControllerResponsePlan::jsonObject([
			'slow' => Promise::async(static fn (FiberHttpClient $c): ResponseFragment => ResponseFragment::jsonObject(
				['first' => $c->request('GET', 'https://example.test/slow')->getContent()],
				order: 10,
			)),
			'fast' => Promise::async(static fn (FiberHttpClient $c): ResponseFragment => ResponseFragment::jsonObject(
				['second' => $c->request('GET', 'https://example.test/fast')->getContent()],
				order: 20,
			)),
		]));
		self::assertSame(
			['first', 'second'],
			array_keys(json_decode((string) $jsonObject->getContent(), true, flags: JSON_THROW_ON_ERROR)),
		);

		$fastDone = false;
		$jsonArray = $composer->compose(ControllerResponsePlan::jsonArray([
			'slow' => Promise::async(static fn (FiberHttpClient $c): ResponseFragment => ResponseFragment::jsonArray(
				[$c->request('GET', 'https://example.test/slow')->getContent()],
				order: 10,
			)),
			'fast' => Promise::async(static fn (FiberHttpClient $c): ResponseFragment => ResponseFragment::jsonArray(
				[$c->request('GET', 'https://example.test/fast')->getContent()],
				order: 20,
			)),
		]));
		self::assertSame(
			['slow', 'fast'],
			json_decode((string) $jsonArray->getContent(), true, flags: JSON_THROW_ON_ERROR),
		);

		$fastDone = false;
		$text = $composer->compose(ControllerResponsePlan::text([
			'slow' => Promise::async(static fn (FiberHttpClient $c): ResponseFragment => ResponseFragment::text(
				$c->request('GET', 'https://example.test/slow')->getContent().'-',
				order: 10,
			)),
			'fast' => Promise::async(static fn (FiberHttpClient $c): ResponseFragment => ResponseFragment::text(
				$c->request('GET', 'https://example.test/fast')->getContent(),
				order: 20,
			)),
		]));
		self::assertSame('slow-fast', $text->getContent());
	}
}
