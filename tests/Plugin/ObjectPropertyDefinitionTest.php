<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionProviderInterface;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Service\ObjectPropertyValueValidator;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Value\LocalizedValue;
use PHPUnit\Framework\TestCase;

final class ObjectPropertyDefinitionTest extends TestCase
{
	public function testCanonicalizesPropertyLocalesAndRetainsCapabilities(): void
	{
		$definition = new ObjectPropertyDefinition(
			'Article',
			'title',
			['en-ca', 'fr-CA'],
			LocaleTag::fromString('en-CA'),
			searchable: true,
			sortable: true,
		);

		self::assertSame(['en-CA', 'fr-CA'], $definition->locales);
		self::assertSame('article::title', $definition->key());
		self::assertTrue($definition->supportsLocale('fr-CA'));
		self::assertTrue($definition->searchable);
		self::assertTrue($definition->sortable);
	}

	public function testSearchableAndSortableDefinitionsRequireLocales(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new ObjectPropertyDefinition('Article', 'title', searchable: true);
	}

	public function testDefaultLocaleMustBeDeclared(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new ObjectPropertyDefinition('Article', 'title', ['fr'], LocaleTag::fromString('en'));
	}

	public function testBaseLanguageDeclarationCoversRegionalContentOnly(): void
	{
		$base = new ObjectPropertyDefinition('Article', 'title', ['en']);
		$regional = new ObjectPropertyDefinition('Article', 'caption', ['en-CA']);

		self::assertTrue($base->supportsLocale('en-CA'));
		self::assertFalse($regional->supportsLocale('en-GB'));
	}

	public function testRegistryRejectsDuplicatePropertyDefinitions(): void
	{
		$definition = new ObjectPropertyDefinition('Article', 'title', ['en']);
		$provider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$provider->method('getObjectPropertyDefinitions')->willReturn([$definition]);
		$duplicateProvider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$duplicateProvider->method('getObjectPropertyDefinitions')->willReturn([$definition]);

		$this->expectException(\LogicException::class);
		new ObjectPropertyDefinitionRegistry([$provider, $duplicateProvider]);
	}

	public function testObjectSpecificDefinitionTakesPrecedenceOverCoreWildcard(): void
	{
		$core = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$core->method('getObjectPropertyDefinitions')->willReturn([
			new ObjectPropertyDefinition('*', 'title', searchable: true, sortable: true, allowAnyLocale: true),
		]);
		$plugin = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$plugin->method('getObjectPropertyDefinitions')->willReturn([
			new ObjectPropertyDefinition('Page', 'title', ['fr-CA'], LocaleTag::fromString('fr-CA'), searchable: true),
		]);
		$registry = new ObjectPropertyDefinitionRegistry([$core, $plugin]);

		self::assertSame(['fr-CA'], $registry->get('page', 'title')?->locales);
		self::assertTrue($registry->get('application', 'title')?->allowAnyLocale);
	}

	public function testValidatorEnforcesDeclaredLocalesAndMixedContentCapability(): void
	{
		$definition = new ObjectPropertyDefinition(
			'Page',
			'title',
			['en-CA', 'fr-CA'],
			LocaleTag::fromString('en-CA'),
			allowMixedLanguage: true,
		);
		$provider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$provider->method('getObjectPropertyDefinitions')->willReturn([$definition]);
		$registry = new ObjectPropertyDefinitionRegistry([$provider]);
		$validator = new ObjectPropertyValueValidator($registry);
		$object = new DbObject();
		(new \ReflectionProperty(DbObject::class, 'type'))->setValue($object, 'Page');
		$property = new ObjectProperty();
		$property->setName('title');
		$property->setValue(LocalizedValue::fromSegments([
			['text' => 'Bonjour', 'locale' => 'fr-CA'],
			['text' => ' world', 'locale' => 'en-CA'],
		]));

		$validator->validate($object, $property);

		$property->setValue(LocalizedValue::fromTranslations(['es' => 'Hola']));
		$this->expectException(\InvalidArgumentException::class);
		$validator->validate($object, $property);
	}

	public function testValidatorRejectsLocalizedPropertiesWithoutDefinitions(): void
	{
		$validator = new ObjectPropertyValueValidator(new ObjectPropertyDefinitionRegistry([]));
		$property = new ObjectProperty();
		$property->setName('title');
		$property->setValue(LocalizedValue::fromTranslations(['en' => 'Hello']));

		$this->expectException(\InvalidArgumentException::class);
		$validator->validate(new DbObject(), $property);
	}

	public function testValidatorEnforcesObjectEnabledLocales(): void
	{
		$definition = new ObjectPropertyDefinition('page', 'title', ['en', 'fr']);
		$provider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$provider->method('getObjectPropertyDefinitions')->willReturn([$definition]);
		$validator = new ObjectPropertyValueValidator(new ObjectPropertyDefinitionRegistry([$provider]));
		$object = new DbObject();
		(new \ReflectionProperty(DbObject::class, 'type'))->setValue($object, 'page');
		$object->setLocaleSettings('en', null, ['en']);
		$property = new ObjectProperty();
		$property->setName('title');
		$property->setValue(LocalizedValue::fromTranslations(['fr' => 'Bonjour']));

		$this->expectException(\InvalidArgumentException::class);
		$validator->validate($object, $property);
	}
}
