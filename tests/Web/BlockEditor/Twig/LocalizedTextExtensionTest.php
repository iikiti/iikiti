<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Twig;

use iikiti\CMS\Web\BlockEditor\Twig\LocalizedTextExtension;
use PHPUnit\Framework\TestCase;

final class LocalizedTextExtensionTest extends TestCase
{
	public function testLocalizedTextRendersEscapedLanguageTaggedSpans(): void
	{
		$extension = new LocalizedTextExtension();

		self::assertSame(
			'<span lang="fr-CA">Bonjour, </span><span lang="en-CA">&lt;img src=x onerror=alert(1)&gt;</span>',
			$extension->render([
				['text' => 'Bonjour, ', 'locale' => 'fr-CA'],
				['text' => '<img src=x onerror=alert(1)>', 'locale' => 'en-CA'],
			]),
		);
	}

	public function testLocalizedPlainTextJoinsSegmentsWithoutMarkup(): void
	{
		$extension = new LocalizedTextExtension();

		self::assertSame('Bonjour, &lt;img src=x&gt;', $extension->renderPlain([
			['text' => 'Bonjour, ', 'locale' => 'fr-CA'],
			['text' => '<img src=x>', 'locale' => 'en-CA'],
		]));
	}

	public function testMalformedSegmentListsRenderNothing(): void
	{
		$extension = new LocalizedTextExtension();

		self::assertSame('', $extension->render([['text' => '<script>x</script>', 'locale' => 'invalid_tag']]));
		self::assertSame('', $extension->renderPlain(['not a localized segment']));
	}
}
