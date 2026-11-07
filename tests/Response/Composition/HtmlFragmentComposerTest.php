<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Response\Composition;

use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HtmlFragmentComposerTest extends TestCase
{
	public function testInsertsMultipleRootsInDeclaredOrderAndRemovesPlaceholder(): void
	{
		$composer = new HtmlFragmentComposer(
			'<!DOCTYPE html><html><body><header>Header</header><template id="page"></template><footer>Footer</footer></body></html>',
		);
		$composer->insertBefore('#page', '<main id="main">Main</main>', order: 20);
		$composer->insertBefore('#page', '<aside id="aside">Aside</aside><section id="extra">Extra</section>', order: 10);

		$html = $composer->render();

		self::assertStringNotContainsString('id="page"', $html);
		self::assertLessThan(strpos($html, 'id="main"'), strpos($html, 'id="aside"'));
		self::assertStringContainsString('<section id="extra">Extra</section>', $html);
		self::assertStringContainsString('<header>Header</header>', $html);
		self::assertStringContainsString('<footer>Footer</footer>', $html);
	}

	public function testMissingPlaceholderIsAnError(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('placeholder "#missing" was not found');

		$composer = new HtmlFragmentComposer('<!DOCTYPE html><html><body></body></html>');
		$composer->insertBefore('#missing', '<p>content</p>');
		$composer->render();
	}

	public function testAddsAndResolvesIndividualDomFragmentsWithoutReparsingOutput(): void
	{
		$composer = new HtmlFragmentComposer('<!DOCTYPE html><html><body><template id="page"></template></body></html>');
		$composer->insertIntoPlaceholder('#page', '<main><template data-iikiti-async-embed="token"></template></main>');
		$composer->replaceDeferredEmbeds(['token' => '<blockquote>Loaded</blockquote>']);
		$document = $composer->document();
		$response = new DomHtmlResponse($document);

		self::assertSame($document, $response->document());
		self::assertNotNull($document->querySelector('main blockquote'));
		self::assertStringContainsString('<blockquote>Loaded</blockquote>', $response->getContent());
		self::assertStringNotContainsString('data-iikiti-async-embed', $response->getContent());
	}

	public function testHeadFragmentsRemainInTheHead(): void
	{
		$composer = new HtmlFragmentComposer('<!DOCTYPE html><html><head><template id="styles"></template></head><body></body></html>');
		$composer->insertIntoPlaceholder('#styles', '<link id="font" rel="stylesheet" href="icons.css"><style id="custom">body{color:red}</style>');
		$document = $composer->document();

		self::assertSame('head', strtolower($document->querySelector('#font')->parentNode->nodeName));
		self::assertSame('head', strtolower($document->querySelector('#custom')->parentNode->nodeName));
	}
}
