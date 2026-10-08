<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Audit;

use iikiti\CMS\Audit\AuditSubscriber;
use iikiti\CMS\Entity\Object\ApiToken;
use PHPUnit\Framework\TestCase;

final class AuditSubscriberTest extends TestCase
{
	public function testApiTokenIsExplicitlyRecordedAndSkippedByTheSubscriber(): void
	{
		$constant = new \ReflectionClassConstant(AuditSubscriber::class, 'EXPLICITLY_RECORDED');

		self::assertContains(ApiToken::class, $constant->getValue());
	}
}
