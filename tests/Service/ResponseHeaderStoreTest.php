<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderStore;
use PHPUnit\Framework\TestCase;

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
}
