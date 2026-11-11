<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Secrets\SecretsApi;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final class SecretsApiWiringTest extends KernelTestCase
{
	public function testPublicSecretsServiceAndApiSerializerExcludeSecretProperties(): void
	{
		self::bootKernel();
		$container = self::getContainer();
		self::assertInstanceOf(SecretsApi::class, $container->get(SecretsApi::class));
		$serializer = $container->get('serializer');
		self::assertInstanceOf(SerializerInterface::class, $serializer);

		$object = new Page();
		$object->setCreatedDate(new \DateTimeImmutable());
		$object->setCreatorId(1);
		$object->setProperty('title', 'Public title');
		$secret = new ObjectProperty();
		$secret->setName('integration_config');
		$secret->setObject($object);
		$secret->writeEncryptedSecretPayload([
			'version' => 1,
			'nonce' => base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES)),
			'ciphertext' => 'not-for-serialization',
		]);
		$object->getProperties()->set('integration_config', $secret);
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($object, 91);
		(new \ReflectionProperty(ObjectProperty::class, 'id'))->setValue($object->getProperties()->get('title'), 91);
		(new \ReflectionProperty(ObjectProperty::class, 'id'))->setValue($secret, 92);

		$json = $serializer->serialize($object, 'json', [
			AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn (object $value): string => 'object-ref',
		]);

		self::assertStringContainsString('Public title', $json);
		self::assertStringNotContainsString('integration_config', $json);
		self::assertStringNotContainsString('not-for-serialization', $json);
	}
}
