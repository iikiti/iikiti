<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ApiResource;

use iikiti\CMS\ApiResource\Authentication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class AuthenticationResourceSerializationTest extends TestCase
{
	public function testLoginResponseNeverSerializesSubmittedPassword(): void
	{
		$serializer = $this->serializer();
		$authentication = new Authentication('alice', 'correct horse battery staple');
		$authentication->success = true;

		$response = $serializer->serialize($authentication, 'json', ['groups' => ['authentication:read']]);

		self::assertStringNotContainsString('password', $response);
		self::assertStringNotContainsString('correct horse battery staple', $response);
		self::assertStringContainsString('alice', $response);
		self::assertStringContainsString('success', $response);
	}

	public function testLoginRequestStillDenormalizesPassword(): void
	{
		$serializer = $this->serializer();
		$authentication = $serializer->deserialize(
			'{"username":"alice","password":"secret"}',
			Authentication::class,
			'json',
			['groups' => ['authentication:write']],
		);

		self::assertSame('alice', $authentication->username);
		self::assertSame('secret', $authentication->password);
	}

	private function serializer(): Serializer
	{
		$metadata = new ClassMetadataFactory(new AttributeLoader());

		return new Serializer([new ObjectNormalizer($metadata)], [new JsonEncoder()]);
	}
}
