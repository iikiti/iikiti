<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Value;

use iikiti\CMS\Value\TimeZone;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class TimeZoneTest extends TestCase
{
	public function testValidIdentifierIsKeptAsText(): void
	{
		$zone = TimeZone::fromString('Europe/Paris');

		self::assertSame('Europe/Paris', $zone->getId());
		self::assertSame('Europe/Paris', (string) $zone);
	}

	public function testUnknownIdentifierIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown time zone "Mars/Olympus".');

		TimeZone::fromString('Mars/Olympus');
	}

	public function testEmptyAndNullMeanNoZone(): void
	{
		self::assertNull(TimeZone::fromNullableString(null));
		self::assertNull(TimeZone::fromNullableString(''));
	}

	public function testDateTimeZoneFallbackAlwaysAvailable(): void
	{
		$zone = TimeZone::fromString('Europe/Paris');

		self::assertInstanceOf(\DateTimeZone::class, $zone->toDateTimeZone());
		self::assertSame('Europe/Paris', $zone->toDateTimeZone()->getName());
	}

	#[RequiresPhpExtension('intl')]
	public function testPrefersIntlTimeZoneWhenIntlIsLoaded(): void
	{
		$zone = TimeZone::fromString('Europe/Paris');

		self::assertInstanceOf(\IntlTimeZone::class, $zone->toIntl());
		self::assertInstanceOf(\IntlTimeZone::class, $zone->toZone());
	}

	public function testToZoneReturnsTheConcreteZoneForTheRuntime(): void
	{
		$zone = TimeZone::fromString('Europe/Paris')->toZone();

		if (extension_loaded('intl')) {
			self::assertInstanceOf(\IntlTimeZone::class, $zone);

			return;
		}

		self::assertInstanceOf(\DateTimeZone::class, $zone);
	}
}
