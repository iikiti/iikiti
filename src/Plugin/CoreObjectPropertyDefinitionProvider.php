<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

/** Default query capabilities for the common textual object properties. */
final class CoreObjectPropertyDefinitionProvider implements ObjectPropertyDefinitionProviderInterface
{
	public function getObjectPropertyDefinitions(): iterable
	{
		return [
			new ObjectPropertyDefinition('*', 'title', allowMixedLanguage: true, searchable: true, sortable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('*', 'slug', allowMixedLanguage: true, searchable: true, sortable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('*', 'tags', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('*', 'content', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('uploadeddigitalasset', 'default_alt', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('uploadeddigitalasset', 'default_caption', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('uploadeddigitalasset', 'description', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('externaldigitalasset', 'default_alt', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('externaldigitalasset', 'default_caption', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
			new ObjectPropertyDefinition('externaldigitalasset', 'description', allowMixedLanguage: true, searchable: true, allowAnyLocale: true),
		];
	}
}
