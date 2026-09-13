<?php

namespace iikiti\CMS\Tests\Cache;

use iikiti\CMS\Cache\StrategyMetadata;
use PHPUnit\Framework\TestCase;

final class StrategyMetadataTest extends TestCase
{
	public function testMetadataFields(): void
	{
		$metadata = new StrategyMetadata(
			'redis_cache',
			'Redis Cache',
			'Stores entries in Redis.',
			['method_level', 'tags']
		);

		$this->assertSame('redis_cache', $metadata->name);
		$this->assertSame('Redis Cache', $metadata->label);
		$this->assertSame('Stores entries in Redis.', $metadata->description);
		$this->assertSame(['method_level', 'tags'], $metadata->capabilities);
	}

	public function testDefaults(): void
	{
		$metadata = new StrategyMetadata('none', 'Disabled');

		$this->assertSame('', $metadata->description);
		$this->assertSame([], $metadata->capabilities);
	}
}
