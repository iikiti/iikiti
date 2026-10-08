<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

use iikiti\CMS\Entity\DbObject;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for plugin-provided query field "functions": dynamically generated
 * fields that are offered in the block editor's query binding picker and
 * resolved per query result at render time.
 *
 * Implementations are auto-tagged `iikiti.cms.query_field` (via the attribute on
 * this interface) and collected by {@see QueryFieldCatalog}, alongside the
 * reflection-based object/property/related fields the core provides.
 */
#[AutoconfigureTag('iikiti.cms.query_field')]
interface QueryFieldFunctionInterface
{
	/**
	 * Mappable field specs offered for the given object type (null = generic).
	 * Keys must be namespaced (e.g. `excerpt`, resolved through
	 * {@see value()}), and must be unique across providers.
	 *
	 * @return list<array{key:string, label:string, group?:string}>
	 */
	public function fields(?string $objectType): array;

	/**
	 * Resolve one field for a query result row. Return null to fall back to the
	 * bound field's static content. Values are HTML-escaped by the renderer —
	 * return plain text, never markup.
	 *
	 * @param list<string> $args Extra segments after `fn:<key>:` in the spec
	 */
	public function value(DbObject $item, string $key, array $args = []): ?string;
}
