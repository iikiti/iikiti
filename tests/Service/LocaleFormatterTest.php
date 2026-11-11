<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Event\Subscriber\RequestLocaleSubscriber;
use iikiti\CMS\Service\LocaleFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LocaleFormatterTest extends TestCase
{
	public function testUsesRegionalPresentationLocaleAndUserTimezone(): void
	{
		$request = Request::create('/');
		$request->setLocale('en_CA');
		$request->attributes->set(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE, [
			'ui' => 'fr-CA',
			'presentation' => 'en-CA',
			'timeZone' => 'America/Toronto',
		]);
		$stack = new RequestStack();
		$stack->push($request);
		$formatter = new LocaleFormatter($stack);
		$timestamp = (new \DateTimeImmutable('2026-01-01T02:30:00Z'))->getTimestamp();

		self::assertSame(
			(new \IntlDateFormatter('en_CA', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'America/Toronto'))->format($timestamp),
			$formatter->formatDate('2026-01-01T02:30:00Z'),
		);
		self::assertSame(
			(new \NumberFormatter('en_CA', \NumberFormatter::DECIMAL))->format(12345.67),
			$formatter->formatNumber(12345.67),
		);
	}

	public function testUsesRequestLocaleWhenNoPresentationContextExists(): void
	{
		$request = Request::create('/');
		$request->setLocale('fr_CA');
		$stack = new RequestStack();
		$stack->push($request);
		$formatter = new LocaleFormatter($stack);

		self::assertSame(
			(new \NumberFormatter('fr_CA', \NumberFormatter::DECIMAL))->format(1234.5),
			$formatter->formatNumber(1234.5),
		);
	}

	public function testInvalidDateValuesReturnAnEmptyString(): void
	{
		self::assertSame('', (new LocaleFormatter(new RequestStack()))->formatDate('not-a-date'));
	}

	public function testContentLocaleFormatsWhenThereIsNoUserPreference(): void
	{
		$request = Request::create('/');
		$request->setLocale('fr_CA');
		$request->attributes->set(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE, [
			'ui' => 'fr-CA',
			'presentation' => 'fr-CA',
			'userPreferred' => null,
			'timeZone' => null,
		]);
		$stack = new RequestStack();
		$stack->push($request);

		self::assertSame(
			(new \NumberFormatter('en_GB', \NumberFormatter::DECIMAL))->format(1234.5),
			(new LocaleFormatter($stack))->formatNumber(1234.5, 'en-GB'),
		);
	}
}
