<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service\Consent;

require_once __DIR__.'/../../../plugins/consent-management/src/Service/LegalFrameworkResolver.php';

use iikiti\Extension\ConsentManagement\Service\LegalFrameworkResolver;
use PHPUnit\Framework\TestCase;

final class LegalFrameworkResolverTest extends TestCase
{
	public function testMapsEeaCountriesToGdpr(): void
	{
		$resolver = new LegalFrameworkResolver();
		foreach (['DE', 'FR', 'ES', 'IT', 'NL', 'SE', 'IE', 'PL'] as $country) {
			self::assertSame('gdpr', $resolver->resolve($country));
		}
	}

	public function testMapsUsToCcpaAndBrazilToLgpdAndCanadaToPipeda(): void
	{
		$resolver = new LegalFrameworkResolver();
		self::assertSame('ccpa', $resolver->resolve('US'));
		self::assertSame('lgpd', $resolver->resolve('BR'));
		self::assertSame('pipeda', $resolver->resolve('CA'));
	}

	public function testUnknownCountryReturnsNone(): void
	{
		$resolver = new LegalFrameworkResolver();
		self::assertSame('none', $resolver->resolve('JP'));
		self::assertSame('none', $resolver->resolve(null));
		self::assertSame('none', $resolver->resolve(''));
	}

	public function testSiteOverrideTakesPrecedence(): void
	{
		$resolver = new LegalFrameworkResolver(['US' => 'gdpr', 'JP' => 'ccpa']);
		self::assertSame('gdpr', $resolver->resolve('US'));
		self::assertSame('ccpa', $resolver->resolve('JP'));
		self::assertSame('lgpd', $resolver->resolve('BR'));
	}

	public function testIsCaseInsensitive(): void
	{
		$resolver = new LegalFrameworkResolver();
		self::assertSame('gdpr', $resolver->resolve('de'));
		self::assertSame('gdpr', $resolver->resolve('De'));
	}
}
