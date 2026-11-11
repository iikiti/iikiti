<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity;

use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Value\LocalizedValue;
use PHPUnit\Framework\TestCase;

final class ObjectPropertyLocalizedValueTest extends TestCase
{
	public function testStoresAndResolvesLocalizedValuesInTheExistingJsonColumn(): void
	{
		$property = new ObjectProperty();
		$property->setLocalizedValue(LocalizedValue::fromTranslations(['en' => 'Hello', 'fr' => 'Bonjour']));

		self::assertSame('Hello', $property->getValueForLocale(
			new LocaleResolver(),
			LocaleTag::fromString('en-CA'),
			null,
			null,
			null,
		));
		self::assertSame(['$type' => 'localized', 'version' => 1, 'translations' => ['en' => 'Hello', 'fr' => 'Bonjour']], $property->getValue());
	}

	public function testLeavesLegacyPropertyValuesUnchanged(): void
	{
		$property = new ObjectProperty();
		$property->setValue('Legacy text');

		self::assertSame('Legacy text', $property->getValueForLocale(new LocaleResolver()));
		self::assertNull($property->getLocalizedValue());
	}

	public function testRejectsMalformedLocalizedValuesBeforePersistence(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new ObjectProperty())->setValue([
			'$type' => 'localized',
			'version' => 1,
			'translations' => ['en_US' => 'Hello'],
		]);
	}

	public function testSecretsCannotBeReplacedByLocalizedValues(): void
	{
		$property = new ObjectProperty();
		$property->writeEncryptedSecretPayload(['version' => 1, 'nonce' => 'nonce', 'ciphertext' => 'secret']);

		$this->expectException(\LogicException::class);
		$property->setLocalizedValue(LocalizedValue::fromTranslations(['en' => 'text']));
	}
}
