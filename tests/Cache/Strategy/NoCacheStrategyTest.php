<?php

namespace iikiti\CMS\Tests\Cache\Strategy;

use Doctrine\ORM\Query;
use iikiti\CMS\Cache\Strategy\NoCacheStrategy;
use PHPUnit\Framework\TestCase;

final class NoCacheStrategyTest extends TestCase
{
	private NoCacheStrategy $strategy;

	protected function setUp(): void
	{
		$this->strategy = new NoCacheStrategy();
	}

	public function testMetadata(): void
	{
		$this->assertSame('none', $this->strategy->getName());
		$this->assertSame('Disabled', $this->strategy->getLabel());
		$this->assertNotSame('', $this->strategy->getDescription());
		$this->assertSame([], $this->strategy->getCapabilities());
	}

	public function testIsNeverEnabled(): void
	{
		$this->assertFalse($this->strategy->isEnabled([]));
		$this->assertFalse($this->strategy->isEnabled(['cache' => true]));
		$this->assertFalse($this->strategy->isEnabled(['cache' => false]));
	}

	public function testDecorateQueryIsNoOp(): void
	{
		/** @var Query<array-key,mixed>&\PHPUnit\Framework\MockObject\MockObject $query */
		$query = $this->createMock(Query::class);
		$query->expects($this->never())->method('enableResultCache');

		$this->strategy->decorateQuery($query, 'key', 300);
	}

	public function testCacheResultInvokesCallback(): void
	{
		$invocations = 0;
		$result = $this->strategy->cacheResult('key', function () use (&$invocations) {
			++$invocations;

			return 'value';
		}, 300, ['tag']);

		$this->assertSame('value', $result);
		$this->assertSame(1, $invocations);
	}

	public function testInvalidateAndClearAreNoOps(): void
	{
		$this->strategy->invalidate('App\Entity\Thing');
		$this->strategy->clear();
		$this->addToAssertionCount(2);
	}
}
