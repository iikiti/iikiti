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

	public function testTableAndSelectFragmentsPreserveContextSensitiveElements(): void
	{
		$composer = new HtmlFragmentComposer(
			'<!DOCTYPE html><html><body><table><tbody><template id="rows"></template></tbody></table><select><template id="opts"></template></select><template id="empty"></template></body></html>',
		);
		$composer->insertIntoPlaceholder('#rows', '<tr><td id="cell">Row 1</td></tr>');
		$composer->insertIntoPlaceholder('#opts', '<option value="a">Alpha</option>');
		$composer->insertIntoPlaceholder('#empty', '');

		$document = $composer->document();
		self::assertSame('Row 1', $document->querySelector('table tbody tr td#cell')?->textContent);
		self::assertSame('Alpha', $document->querySelector('select option[value="a"]')?->textContent);
		self::assertNull($document->querySelector('#empty'));
	}

	public function testDomHtmlResponseSerializesLazilyAndPreservesExplicitSetContent(): void
	{
		$composer = new HtmlFragmentComposer('<!DOCTYPE html><html><body><p id="msg">Initial</p></body></html>');
		$response = new DomHtmlResponse($composer->document());

		// Mutate the retained DOM before first getContent() — lazy serialization captures it.
		$response->document()->querySelector('#msg')->textContent = 'Mutated';
		self::assertStringContainsString('<p id="msg">Mutated</p>', (string) $response->getContent());

		// Explicit setContent() (e.g. CSP nonce injection or WDT) must not be overwritten on sendContent().
		$response->setContent('<html><body><script nonce="abc"></script></body></html>');
		ob_start();
		$response->sendContent();
		$sent = ob_get_clean();
		self::assertSame('<html><body><script nonce="abc"></script></body></html>', $sent);
	}

	public function testComposerMaintainsSingleIndexedDocumentAcrossMutationsAndDomHtmlResponse(): void
	{
		$composer = new HtmlFragmentComposer(
			'<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><template id="before"></template><template id="main"></template></body></html>',
		);
		$composer->insertBefore('#before', '<aside class="slot-item">Before</aside>', order: 10);
		$composer->insertIntoPlaceholder('template[data-iikiti-fragment="custom-css"]', '<style>.slot-item{color:blue}</style>');
		$composer->insertIntoPlaceholder('#main', '<main><template data-iikiti-async-embed="e1"></template></main>');
		$composer->replaceDeferredEmbeds(['e1' => '<p class="slot-item embedded">Async</p>']);

		$indexedDocument = $composer->indexedDocument();
		$response = new DomHtmlResponse($indexedDocument);

		self::assertSame($indexedDocument, $response->indexedDocument());
		self::assertSame($indexedDocument->document(), $response->document());
		self::assertFalse($indexedDocument->isDirty());
		self::assertSame(1, $indexedDocument->indexStats()->rebuildCount);
		self::assertSame(2, $indexedDocument->querySelectorAll('.slot-item')->count());
		self::assertSame('Async', $indexedDocument->querySelector('.slot-item.embedded')?->textContent);
	}
}
