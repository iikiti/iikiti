<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ResponseHeaderStoreTest extends TestCase
{
	public function testVersionsAndNormalizesForBothOwners(): void
	{
		$store = new ResponseHeaderStore(new ResponseHeaderPolicy());
		foreach ([new Application(), new Site()] as $owner) {
			$initial = $store->getPolicy($owner);
			self::assertSame(['version' => 1, 'rules' => []], $initial);
			self::assertSame(2, $store->setPolicy($owner, [['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex']]));
			self::assertSame('x-robots-tag', $store->getPolicy($owner)['rules'][0]['name']);
			self::assertSame(3, $store->setPolicy($owner, []));
		}
	}

	public function testInvalidWriteDoesNotChangePolicy(): void
	{
		$store = new ResponseHeaderStore(new ResponseHeaderPolicy());
		$site = new Site();
		try {
			$store->setPolicy($site, [['name' => 'Content-Length', 'action' => 'remove']]);
			self::fail('Expected validation failure');
		} catch (\InvalidArgumentException) {
			self::assertSame(['version' => 1, 'rules' => []], $store->getPolicy($site));
		}
	}

	public function testMalformedPolicyIsRejected(): void
	{
		$site = new Site();
		$site->setProperty('response_headers_policy', ['version' => -1, 'rules' => []]);
		$this->expectException(\UnexpectedValueException::class);
		(new ResponseHeaderStore(new ResponseHeaderPolicy()))->getPolicy($site);
	}

	public function testContentHashCacheReflectsInMemorySetPolicyImmediately(): void
	{
		$cache = new ArrayAdapter();
		$store = new ResponseHeaderStore(new ResponseHeaderPolicy(), $cache);
		$site = new Site();

		$store->setPolicy($site, [['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex']]);
		$first = $store->getPolicy($site);
		self::assertSame('noindex', $first['rules'][0]['value']);
		self::assertCount(1, $cache->getValues());

		// Second read hits cache; updating policy in memory before flush() produces a new key immediately.
		self::assertSame($first, $store->getPolicy($site));
		$store->setPolicy($site, [['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'index']]);
		$updated = $store->getPolicy($site);
		self::assertSame('index', $updated['rules'][0]['value']);
		self::assertCount(2, $cache->getValues());
	}
}
