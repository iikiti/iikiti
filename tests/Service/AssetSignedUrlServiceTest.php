<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Service\AssetSignedUrlService;
use PHPUnit\Framework\TestCase;

final class AssetSignedUrlServiceTest extends TestCase
{
	public function testGeneratesAndVerifiesSignedAssetUrl(): void
	{
		$service = new AssetSignedUrlService('dedicated-asset-signing-secret', 'fallback-app-secret');
		$token = str_repeat('a', 64);
		$asset = new UploadedDigitalAsset('Contract', 'application/pdf', $token);
		$site = new Site();
		$site->setId(42);

		$now = 1764300000;
		$result = $service->generate($asset, $site, 'original', 600, 'attachment', 3, $now);

		self::assertStringStartsWith('/assets/signed/'.$token.'/original?', $result['url']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['signature']);
		self::assertSame((new \DateTimeImmutable('@'.($now + 600)))->format(\DateTimeInterface::ATOM), $result['expiresAt']);

		parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);
		self::assertSame((string) ($now + 600), $query['exp'] ?? null);
		self::assertSame('42', $query['site'] ?? null);
		self::assertSame('attachment', $query['d'] ?? null);
		self::assertSame('3', $query['v'] ?? null);
		self::assertSame($result['signature'], $query['sig'] ?? null);

		self::assertTrue($service->verify($token, 'original', 42, $now + 600, 'attachment', $result['signature'], 3, $now + 599));
		self::assertFalse($service->verify($token, 'original', 42, $now + 600, 'attachment', $result['signature'], 3, $now + 601));
		self::assertFalse($service->verify($token, 'thumbnail', 42, $now + 600, 'attachment', $result['signature'], 3, $now));
		self::assertFalse($service->verify($token, 'original', 99, $now + 600, 'attachment', $result['signature'], 3, $now));
		self::assertFalse($service->verify($token, 'original', 42, $now + 600, 'inline', $result['signature'], 3, $now));
		self::assertFalse($service->verify($token, 'original', 42, $now + 600, 'attachment', $result['signature'], 4, $now));
	}

	public function testFallsBackToApplicationSecretWhenSigningSecretIsEmpty(): void
	{
		$service = new AssetSignedUrlService('', 'app-secret-only');
		$token = str_repeat('b', 64);
		$asset = new UploadedDigitalAsset('Photo', 'image/png', $token);
		$site = new Site();
		$site->setId(7);

		$now = 1764300000;
		$signed = $service->generate($asset, $site, 'thumbnail', 900, 'inline', null, $now);

		self::assertTrue($service->verify($token, 'thumbnail', 7, $now + 900, 'inline', $signed['signature'], null, $now));

		$differentSecret = new AssetSignedUrlService('', 'other-secret');
		self::assertFalse($differentSecret->verify($token, 'thumbnail', 7, $now + 900, 'inline', $signed['signature'], null, $now));
	}

	public function testRejectsOutOfBoundsTtl(): void
	{
		$service = new AssetSignedUrlService('secret');
		$asset = new UploadedDigitalAsset('Photo', 'image/png', str_repeat('c', 64));
		$site = new Site();
		$site->setId(1);

		$this->expectException(\InvalidArgumentException::class);
		$service->generate($asset, $site, 'original', 86401);
	}

	public function testRejectsInvalidDisposition(): void
	{
		$service = new AssetSignedUrlService('secret');
		$asset = new UploadedDigitalAsset('Photo', 'image/png', str_repeat('d', 64));
		$site = new Site();
		$site->setId(1);

		$this->expectException(\InvalidArgumentException::class);
		$service->generate($asset, $site, 'original', 300, 'popup');
	}
}
