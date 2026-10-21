<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use PHPUnit\Framework\TestCase;

final class CustomCssCollectorTest extends TestCase
{
	public function testDeduplicatesChunksAndEscapesStyleEndTags(): void
	{
		$collector = new CustomCssCollector();
		$css = '.scope{content:"</style>"}';
		$collector->append($css);
		$collector->append($css);

		self::assertSame('.scope{content:"\\3C /style>"}', $collector->stylesheet());
	}
}
