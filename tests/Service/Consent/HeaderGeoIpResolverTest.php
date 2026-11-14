<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service\Consent;

require_once __DIR__.'/../../../plugins/consent-management/src/Service/HeaderGeoIpResolver.php';

use iikiti\Extension\ConsentManagement\Service\HeaderGeoIpResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HeaderGeoIpResolverTest extends TestCase
{
	public function testReadsXGeoCountryHeader(): void
	{
		$resolver = new HeaderGeoIpResolver();
		$request = Request::create('/', 'GET', [], [], [], ['HTTP_X_GEO_COUNTRY' => 'DE']);
		self::assertSame('DE', $resolver->resolveCountry($request));
	}

	public function testLowercasesAndTrimsCountry(): void
	{
		$resolver = new HeaderGeoIpResolver();
		$request = Request::create('/', 'GET', [], [], [], ['HTTP_X_GEO_COUNTRY' => ' de ']);
		self::assertSame('DE', $resolver->resolveCountry($request));
	}

	public function testFallsBackToEnvVar(): void
	{
		$_ENV['CONSENT_DEFAULT_COUNTRY'] = 'FR';
		$resolver = new HeaderGeoIpResolver();
		$request = Request::create('/');
		self::assertSame('FR', $resolver->resolveCountry($request));
		unset($_ENV['CONSENT_DEFAULT_COUNTRY']);
	}

	public function testReturnsNullWhenNoSource(): void
	{
		$_ENV['CONSENT_DEFAULT_COUNTRY'] = '';
		$resolver = new HeaderGeoIpResolver();
		$request = Request::create('/');
		self::assertNull($resolver->resolveCountry($request));
		unset($_ENV['CONSENT_DEFAULT_COUNTRY']);
	}

	public function testHeaderTakesPrecedenceOverEnv(): void
	{
		$_ENV['CONSENT_DEFAULT_COUNTRY'] = 'FR';
		$resolver = new HeaderGeoIpResolver();
		$request = Request::create('/', 'GET', [], [], [], ['HTTP_X_GEO_COUNTRY' => 'JP']);
		self::assertSame('JP', $resolver->resolveCountry($request));
		unset($_ENV['CONSENT_DEFAULT_COUNTRY']);
	}
}
