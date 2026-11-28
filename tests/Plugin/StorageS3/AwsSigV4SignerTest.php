<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin\StorageS3;

use iikiti\Extension\StorageS3\Service\AwsSigV4Signer;
use PHPUnit\Framework\TestCase;

final class AwsSigV4SignerTest extends TestCase
{
	public function testPresignGetUrlMatchesOfficialAwsSigV4TestVector(): void
	{
		$signer = new AwsSigV4Signer();
		$now = new \DateTimeImmutable('2013-05-24T00:00:00Z');

		$signedUrl = $signer->presignGetUrl(
			url: 'https://examplebucket.s3.amazonaws.com/test.txt',
			accessKey: 'AKIAIOSFODNN7EXAMPLE',
			secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			region: 'us-east-1',
			expiresInSeconds: 86400,
			now: $now,
		);

		self::assertSame(
			'https://examplebucket.s3.amazonaws.com/test.txt'.
			'?X-Amz-Algorithm=AWS4-HMAC-SHA256'.
			'&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'.
			'&X-Amz-Date=20130524T000000Z'.
			'&X-Amz-Expires=86400'.
			'&X-Amz-SignedHeaders=host'.
			'&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
			$signedUrl,
		);
	}

	public function testSignRequestProducesDeterministicCanonicalHeadersAndAuthorization(): void
	{
		$signer = new AwsSigV4Signer();
		$now = new \DateTimeImmutable('2026-11-28T12:30:00Z');
		$payloadHash = hash('sha256', 'hello s3');

		$first = $signer->signRequest(
			method: 'put',
			url: 'https://assets.s3.us-east-1.amazonaws.com/ab/'.str_repeat('a', 64).'.txt',
			accessKey: 'AKIAEXAMPLE',
			secretKey: 'SECRETEXAMPLE',
			region: 'us-east-1',
			headers: [
				'Content-Type' => 'text/plain',
				'Content-Length' => '8',
			],
			payloadHash: $payloadHash,
			now: $now,
		);

		$second = $signer->signRequest(
			method: 'PUT',
			url: 'https://assets.s3.us-east-1.amazonaws.com/ab/'.str_repeat('a', 64).'.txt',
			accessKey: 'AKIAEXAMPLE',
			secretKey: 'SECRETEXAMPLE',
			region: 'us-east-1',
			headers: [
				'Content-Length' => '8',
				'Content-Type' => 'text/plain',
			],
			payloadHash: $payloadHash,
			now: $now,
		);

		self::assertSame($first['Authorization'], $second['Authorization']);
		self::assertSame('assets.s3.us-east-1.amazonaws.com', $first['Host']);
		self::assertSame('20261128T123000Z', $first['X-Amz-Date']);
		self::assertSame($payloadHash, $first['X-Amz-Content-Sha256']);
		self::assertStringContainsString('AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/20261128/us-east-1/s3/aws4_request', $first['Authorization']);
		self::assertStringContainsString('SignedHeaders=content-length;content-type;host;x-amz-content-sha256;x-amz-date', $first['Authorization']);
		self::assertMatchesRegularExpression('/Signature=[a-f0-9]{64}$/', $first['Authorization']);

		$differentPayload = $signer->signRequest(
			method: 'PUT',
			url: 'https://assets.s3.us-east-1.amazonaws.com/ab/'.str_repeat('a', 64).'.txt',
			accessKey: 'AKIAEXAMPLE',
			secretKey: 'SECRETEXAMPLE',
			region: 'us-east-1',
			headers: [
				'Content-Type' => 'text/plain',
				'Content-Length' => '8',
			],
			payloadHash: hash('sha256', 'different'),
			now: $now,
		);

		self::assertNotSame($first['Authorization'], $differentPayload['Authorization']);
	}

	public function testPresignGetUrlIncludesResponseOverrideParamsAndEncodesSpecialCharacters(): void
	{
		$signer = new AwsSigV4Signer();
		$now = new \DateTimeImmutable('2026-11-28T12:00:00Z');

		$signedUrl = $signer->presignGetUrl(
			url: 'http://minio.local:9000/my-bucket/folder/hello world+~%.pdf',
			accessKey: 'minioadmin',
			secretKey: 'miniosecret',
			region: 'us-east-1',
			expiresInSeconds: 300,
			queryParams: [
				'response-content-type' => 'application/pdf',
				'response-content-disposition' => 'inline; filename="report 2026.pdf"',
			],
			now: $now,
		);

		self::assertStringStartsWith('http://minio.local:9000/my-bucket/folder/hello%20world%2B~%25.pdf?', $signedUrl);
		self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $signedUrl);
		self::assertStringContainsString('X-Amz-Expires=300', $signedUrl);
		self::assertStringContainsString('X-Amz-SignedHeaders=host', $signedUrl);
		self::assertStringContainsString('response-content-type=application%2Fpdf', $signedUrl);
		self::assertStringContainsString('response-content-disposition=inline%3B%20filename%3D%22report%202026.pdf%22', $signedUrl);
		self::assertMatchesRegularExpression('/&X-Amz-Signature=[a-f0-9]{64}$/', $signedUrl);
	}

	public function testUriEncodeConformsToRfc3986(): void
	{
		$signer = new AwsSigV4Signer();

		self::assertSame('a%20b%2Bc~d_e-f.g', $signer->uriEncode('a b+c~d_e-f.g', true));
		self::assertSame('dir/sub%20dir/file.txt', $signer->uriEncode('dir/sub dir/file.txt', false));
		self::assertSame('/prefix/file%20name.txt', $signer->canonicalizeUri('/prefix/file name.txt'));
		self::assertSame('/prefix/file%20name.txt', $signer->canonicalizeUri('/prefix/file%20name.txt'));
	}

	public function testHostHeaderOmitsDefaultPortsAndPreservesCustomPorts(): void
	{
		$signer = new AwsSigV4Signer();
		$now = new \DateTimeImmutable('2026-11-28T12:00:00Z');

		$httpsDefault = $signer->signRequest('GET', 'https://s3.example.test:443/bucket/key', 'k', 's', 'us-east-1', [], null, $now);
		$httpCustom = $signer->signRequest('GET', 'http://minio.local:9000/bucket/key', 'k', 's', 'us-east-1', [], null, $now);

		self::assertSame('s3.example.test', $httpsDefault['Host']);
		self::assertSame('minio.local:9000', $httpCustom['Host']);
	}

	public function testPresignGetUrlRejectsInvalidExpiry(): void
	{
		$signer = new AwsSigV4Signer();

		$this->expectException(\InvalidArgumentException::class);
		$signer->presignGetUrl(
			url: 'https://examplebucket.s3.amazonaws.com/test.txt',
			accessKey: 'AKIAEXAMPLE',
			secretKey: 'SECRETEXAMPLE',
			region: 'us-east-1',
			expiresInSeconds: AwsSigV4Signer::MAX_PRESIGN_EXPIRES + 1,
		);
	}
}
