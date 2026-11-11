<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Serializer\DbObjectSecretNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class DbObjectSecretNormalizerTest extends TestCase
{
	public function testSecretPropertiesAreOmittedFromObjectSerialization(): void
	{
		$object = new Page();
		$object->setProperty('title', 'Public title');
		$secret = new ObjectProperty();
		$secret->setName('integration_config');
		$secret->setObject($object);
		$secret->writeEncryptedSecretPayload(['version' => 1, 'nonce' => base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES)), 'ciphertext' => 'ciphertext-only']);
		(new \ReflectionProperty(ObjectProperty::class, 'id'))->setValue($secret, 2);
		$object->getProperties()->set('integration_config', $secret);
		(new \ReflectionProperty(ObjectProperty::class, 'id'))->setValue($object->getProperties()->get('title'), 1);

		$serializer = new Serializer([new DbObjectSecretNormalizer(), new ObjectNormalizer()]);
		$json = json_encode($serializer->normalize($object, 'json', [
			AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn (object $value): string => 'object-ref',
		]), JSON_THROW_ON_ERROR);

		self::assertStringContainsString('Public title', $json);
		self::assertStringNotContainsString('integration_config', $json);
		self::assertStringNotContainsString('ciphertext-only', $json);
	}
}
