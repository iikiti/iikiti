<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpCidrNormalizerTest extends TestCase
{
	public function testNormalizesIpv4HostBitsToNetworkAddress(): void
	{
		$result = IpCidrNormalizer::normalize('192.0.2.55/24');

		self::assertSame(['cidr' => '192.0.2.0/24', 'ip' => '192.0.2.0', 'prefix' => 24, 'version' => 4], $result);
	}

	public function testNormalizesIpv6HostBitsToNetworkAddress(): void
	{
		$result = IpCidrNormalizer::normalize('2001:db8:0:1::5/64');

		self::assertSame('2001:db8:0:1::/64', $result['cidr']);
		self::assertSame(6, $result['version']);
		self::assertSame(64, $result['prefix']);
	}

	public function testDefaultsPrefixToHostWidth(): void
	{
		self::assertSame('192.0.2.7/32', IpCidrNormalizer::normalize('192.0.2.7')['cidr']);
		self::assertSame('2001:db8::1/128', IpCidrNormalizer::normalize('2001:db8::1')['cidr']);
	}

	public function testTrimsWhitespaceAndAcceptsZeroPrefix(): void
	{
		self::assertSame('0.0.0.0/0', IpCidrNormalizer::normalize("  10.1.2.3/0 ")['cidr']);
	}

	#[DataProvider('invalidInputProvider')]
	public function testRejectsMalformedInput(string $input): void
	{
		$this->expectException(\InvalidArgumentException::class);

		IpCidrNormalizer::normalize($input);
	}

	/** @return iterable<string,array{string}> */
	public static function invalidInputProvider(): iterable
	{
		yield 'empty' => [''];
		yield 'whitespace only' => ['   '];
		yield 'control character' => ["192.0.2.1\x00"];
		yield 'inner space' => ['192.0.2.1 /24'];
		yield 'multiple slashes' => ['192.0.2.0/24/8'];
		yield 'non-digit prefix' => ['192.0.2.0/abc'];
		yield 'signed prefix' => ['192.0.2.0/-1'];
		yield 'leading zero prefix' => ['192.0.2.0/08'];
		yield 'prefix too large v4' => ['192.0.2.0/33'];
		yield 'prefix too large v6' => ['2001:db8::/129'];
		yield 'invalid ip' => ['999.0.0.1'];
		yield 'hostname' => ['example.com'];
		yield 'empty prefix' => ['192.0.2.0/'];
	}

	public function testNormalizeIpCanonicalizesAndRejectsInvalid(): void
	{
		self::assertSame('2001:db8::1', IpCidrNormalizer::normalizeIp(' 2001:0db8:0000::0001 '));
		self::assertSame('192.0.2.1', IpCidrNormalizer::normalizeIp('192.0.2.1'));
		self::assertNull(IpCidrNormalizer::normalizeIp('not-an-ip'));
		self::assertNull(IpCidrNormalizer::normalizeIp(''));
	}

	public function testSubnetKeyGroupsByConfiguredPrefix(): void
	{
		self::assertSame('192.0.2.0/24', IpCidrNormalizer::subnetKey('192.0.2.200'));
		self::assertSame('2001:db8:0:1::/64', IpCidrNormalizer::subnetKey('2001:db8:0:1:ffff::9'));
		self::assertNull(IpCidrNormalizer::subnetKey('garbage'));
	}

	public function testMatchesDelegatesToCidrRanges(): void
	{
		self::assertTrue(IpCidrNormalizer::matches('192.0.2.10', '192.0.2.0/24'));
		self::assertFalse(IpCidrNormalizer::matches('198.51.100.1', '192.0.2.0/24'));
		self::assertFalse(IpCidrNormalizer::matches('invalid', '192.0.2.0/24'));
	}
}
