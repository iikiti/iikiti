<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Serializer;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionProviderInterface;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Serializer\DbObjectLocaleNormalizer;
use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use iikiti\CMS\Value\LocaleTag;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class DbObjectLocaleNormalizerTest extends TestCase
{
	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, true);
		parent::tearDown();
	}

	public function testSerializationIncludesResolvedAndStoredTranslations(): void
	{
		$normalizer = $this->normalizer('fr-CA');
		$page = new Page();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($page, 21);
		$page->setProperty('title', [
			'$type' => 'localized',
			'version' => 1,
			'translations' => ['en' => 'Color', 'fr' => 'Couleur'],
		]);
		(new \ReflectionProperty(ObjectProperty::class, 'id'))->setValue($page->getProperties()->get('title'), 31);

		$normalized = $normalizer->normalize($page, 'json', [
			AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn (object $object): string => 'object-ref',
		]);
		$properties = $normalized['properties'];
		$property = $properties['title'] ?? null;
		if (null === $property) {
			foreach ($properties as $row) {
				if (($row['name'] ?? null) === 'title') {
					$property = $row;
					break;
				}
			}
		}

		self::assertIsArray($property);
		self::assertSame('Couleur', $property['value']);
		self::assertSame('fr', $property['locale']);
		self::assertSame(['en' => 'Color', 'fr' => 'Couleur'], $property['localizedValue']['translations']);
	}

	private function normalizer(string $userLocale): Serializer
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, true);

		$user = new User();
		$user->setPreferredLocale($userLocale);
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);
		$applications = $this->createStub(ApplicationRegistry::class);
		$applications->method('hasCurrentApplication')->willReturn(false);
		$provider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$provider->method('getObjectPropertyDefinitions')->willReturn([
			new ObjectPropertyDefinition('page', 'title', ['en', 'fr'], LocaleTag::fromString('en')),
		]);
		$definitions = new ObjectPropertyDefinitionRegistry([$provider]);
		$properties = new LocalizedPropertyResolver(new LocaleResolver(), $definitions, $applications, $security);
		$localeNormalizer = new DbObjectLocaleNormalizer($properties);
		$serializer = new Serializer([$localeNormalizer, new ObjectNormalizer()]);
		$localeNormalizer->setNormalizer($serializer);

		return $serializer;
	}
}
