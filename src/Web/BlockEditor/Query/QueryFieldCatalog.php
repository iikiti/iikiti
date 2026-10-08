<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

use iikiti\CMS\Entity\DbObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Catalog of mappable query result fields.
 *
 * Used in two places:
 * - `GET /api/editor/query-fields` returns the field list for the binding picker
 *   (object fields via reflection, whitelisted property columns, related-entity
 *   scalar fields one level deep, and plugin-registered field functions);
 * - {@see \iikiti\CMS\Web\BlockEditor\Render\BlockRenderer} resolves a stored
 *   binding spec per query result when rendering per-result child templates.
 *
 * Specs are simple dotted strings so they serialise safely inside the block
 * tree JSON:
 * - `id` / `type` / `created_date`          whitelisted columns
 * - `title` / `slug` / `tags` / `content`   whitelisted property columns
 * - `properties.<name>`                     arbitrary object property
 * - `<relation>.<field>`                    related-entity scalar field
 *                                           (e.g. `site.title`, `creator.name`)
 * - `fn:<key>[:<arg>…]`                     plugin-registered field function
 */
final class QueryFieldCatalog
{
	/** Whitelisted columns (mirrors {@see QueryDefinition::COLUMN_MAP}). */
	private const BASE_FIELDS = ['id', 'type', 'created_date'];

	/** Whitelisted object property columns filterable/selectable by query. */
	private const PROPERTY_FIELDS = ['title', 'slug', 'tags', 'content'];

	/** @var array<class-string, array<string, string>> */
	private static array $reflectionCache = [];

	/**
	 * Per-instance memo of resolved (row, spec) pairs — sibling children that
	 * reference the same field resolve once per request instead of repeating
	 * getter/relation work per child.
	 *
	 * @var array<string, ?string>
	 */
	private array $resolveCache = [];

	/**
	 * @param iterable<QueryFieldFunctionInterface> $functions
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		#[AutowireIterator('iikiti.cms.query_field')]
		private readonly iterable $functions = [],
	) {
	}

	/**
	 * All mappable fields for an object type (freshly named object fields,
	 * reflection-discovered scalar getters, related-entity scalar getters one
	 * level deep, whitelisted property columns, plugin functions).
	 *
	 * @return list<array{key:string, label:string, group:string, kind:string}>
	 */
	public function fields(?string $objectType): array
	{
		/** @var list<array<string,string>> $out */
		$out = [];
		$seen = [];

		$push = function (array $field) use (&$out, &$seen): void {
			$key = (string) ($field['key'] ?? '');
			if ('' === $key || isset($seen[$key])) {
				return;
			}
			$seen[$key] = true;
			$out[] = $field;
		};

		$humanize = static fn (string $key): string => ucfirst(str_replace(['_', '.'], ' ', $key));

		foreach (self::BASE_FIELDS as $key) {
			$push(['key' => $key, 'label' => $humanize($key), 'group' => 'Object', 'kind' => 'field']);
		}
		foreach (self::PROPERTY_FIELDS as $key) {
			$push(['key' => $key, 'label' => $humanize($key), 'group' => 'Properties', 'kind' => 'property']);
		}

		$class = $this->classFor($objectType);
		foreach ($this->scalarsOf($class) as $key => $label) {
			if (in_array($key, self::BASE_FIELDS, true)) {
				continue;
			}
			$push(['key' => $key, 'label' => $label, 'group' => 'Object', 'kind' => 'field']);
		}

		// Related entities one level deep: `relation.field`.
		foreach ($this->relationsOf($class) as $relation => $targetClass) {
			foreach ($this->scalarsOf($targetClass) as $key => $label) {
				$push([
					'key' => $relation.'.'.$key,
					'label' => $humanize($relation).' · '.$label,
					'group' => 'Related ('.$humanize($relation).')',
					'kind' => 'related',
				]);
			}
		}

		// Plugin-registered dynamic field functions.
		foreach ($this->functions as $function) {
			try {
				foreach ($function->fields(self::normalizeObjectType($objectType)) as $spec) {
					$push([
						'key' => 'fn:'.(string) $spec['key'],
						'label' => (string) $spec['label'],
						'group' => (string) ($spec['group'] ?? 'Functions'),
						'kind' => 'function',
					]);
				}
			} catch (\Throwable) {
				// A broken plugin field provider must not break the catalog.
			}
		}

		return $out;
	}

	/**
	 * Resolve one binding spec against a query result row. Returns null when the
	 * spec cannot be resolved (the renderer then keeps the field's static
	 * content). Unknown specs are inert — they never throw and never expose
	 * internals.
	 */
	public function resolve(mixed $item, string $spec): ?string
	{
		if (!$item instanceof DbObject) {
			return null;
		}

		$spec = trim($spec);
		if ('' === $spec || 1 !== preg_match('/^[a-z0-9_.:-]+$/i', $spec)) {
			return null;
		}

		$cacheKey = spl_object_hash($item).'|'.$spec;
		if (array_key_exists($cacheKey, $this->resolveCache)) {
			return $this->resolveCache[$cacheKey];
		}
		$value = $this->resolveUncached($item, $spec);
		// Bounded by (rows × distinct specs) per renderer instance/request.
		$this->resolveCache[$cacheKey] = $value;

		return $value;
	}

	private function resolveUncached(DbObject $item, string $spec): ?string
	{
		if (str_starts_with($spec, 'fn:')) {
			return $this->resolveFunction($item, substr($spec, 3));
		}

		if (str_starts_with($spec, 'properties.')) {
			$name = substr($spec, strlen('properties.'));

			return '' !== $name
				? $this->stringify($item->getProperties()->get($name)?->getValue())
				: null;
		}

		if ('type' === $spec) {
			return $this->stringify($item->getType());
		}
		if ('created_date' === $spec) {
			$date = $item->getCreatedDate();

			return null !== $date ? $date->format('Y-m-d H:i:s') : null;
		}
		if ('id' === $spec) {
			$id = $item->getId();

			return null !== $id ? (string) $id : null;
		}

		// Dotted relation field: `<relation>.<field>` → via `get<Relation>()`.
		if (str_contains($spec, '.')) {
			[$relation, $field] = explode('.', $spec, 2);
			return $this->relatedValue($item, $relation, $field);
		}

		// Whitelisted property column (title/slug/tags/content).
		if (in_array($spec, self::PROPERTY_FIELDS, true)) {
			return $this->stringify($item->getProperties()->get($spec)?->getValue());
		}

		// Reflection-discovered scalar getter of the entity class.
		return $this->getterValue($item, $spec);
	}

	private function resolveFunction(DbObject $item, string $functionSpec): ?string
	{
		// `fn:<key>` or `fn:<key>:<arg>:…` — args pass through verbatim.
		$parts = explode(':', $functionSpec);
		$key = array_shift($parts);
		if ('' === $key) {
			return null;
		}

		foreach ($this->functions as $function) {
			try {
				$value = $function->value($item, $key, $parts);
			} catch (\Throwable) {
				continue;
			}
			if (null !== $value) {
				return $value;
			}
		}

		return null;
	}

	private function relatedValue(DbObject $item, string $relation, string $field): ?string
	{
		if ($this->isSensitive($relation) || $this->isSensitive($field)) {
			return null;
		}
		$relationGetter = 'get'.ucfirst($relation);
		if (!method_exists($item, $relationGetter)) {
			return null;
		}
		try {
			$related = $item->{$relationGetter}();
		} catch (\Throwable) {
			return null; // e.g. a lazy relation that cannot be loaded
		}
		if (!is_object($related)) {
			return null;
		}
		$fieldGetter = 'get'.ucfirst($field);
		if (!method_exists($related, $fieldGetter)) {
			return null;
		}

		try {
			return $this->stringify($related->{$fieldGetter}());
		} catch (\Throwable) {
			return null;
		}
	}

	private function getterValue(DbObject $item, string $key): ?string
	{
		if ($this->isSensitive($key)) {
			return null;
		}
		$getter = 'get'.ucfirst($key);
		if (!method_exists($item, $getter)) {
			return null;
		}

		try {
			return $this->stringify($item->{$getter}());
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Sensitive accessors (password/secret/token/credential/MFA/identifier-style
	 * getters and properties) are never offered or resolved: binding a visible
	 * field to them must not be possible, not even for trusted editors.
	 */
	private function isSensitive(string $name): bool
	{
		return 1 === preg_match('/password|passwd|secret|token|credential|mfa|twofactor|useridentifier|identifier|salt|apikey|api_key|\bauth\b/ui', $name);
	}

	/**
	 * Reduce any value to a renderable plain-text string (null when it is not
	 * text-castable: relations, collections, arrays). The renderer escapes the
	 * result before it reaches markup.
	 */
	private function stringify(mixed $value): ?string
	{
		if (null === $value || is_array($value)) {
			return null;
		}
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_object($value)) {
			if (method_exists($value, '__toString')) {
				return (string) $value;
			}

			return null;
		}

		return (string) $value;
	}

	/**
	 * Map a query `objectType` filter value to an entity class. Object types are
	 * DbObject discriminator values; when unknown, fall back to the base class so
	 * at least the base fields stay available.
	 *
	 * @return class-string
	 */
	private function classFor(?string $objectType): string
	{
		if (null === $objectType || '' === $objectType) {
			return DbObject::class;
		}

		try {
			foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
				$class = $metadata->getName();
				if (is_a($class, DbObject::class, true)
					&& (string) $metadata->discriminatorValue === $objectType
				) {
					return $class;
				}
			}
		} catch (\Throwable) {
			// Metadata unavailable — fall through to the base class.
		}

		return class_exists($objectType) && is_a($objectType, DbObject::class, true) ? $objectType : DbObject::class;
	}

	private static function normalizeObjectType(?string $objectType): ?string
	{
		return null !== $objectType && '' !== $objectType ? $objectType : null;
	}

	/**
	 * Scalar getters of a class (`getX()` → key `x`), only those whose return
	 * types are text-castable (scalar or date, null allowed). Cached per class.
	 *
	 * @param class-string $class
	 * @return array<string, string> key => human label
	 */
	private function scalarsOf(string $class): array
	{
		if (isset(self::$reflectionCache[$class])) {
			return self::$reflectionCache[$class];
		}

		$out = [];
		try {
			$ref = new \ReflectionClass($class);
			foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				if (1 !== preg_match('/^get([A-Z][a-zA-Z0-9]*)$/', $method->getName(), $match)) {
					continue;
				}
				if ($this->isSensitive($match[1])) {
					continue;
				}
				if ($method->getNumberOfRequiredParameters() > 0 || $method->isStatic()) {
					continue;
				}
				if (!$this->isTextCastable($method->getReturnType())) {
					continue;
				}
				$key = lcfirst($match[1]);
				$out[$key] = ucfirst(str_replace('_', ' ', (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $match[1])));
			}
		} catch (\Throwable) {
			// Reflection is best-effort only.
		}

		return self::$reflectionCache[$class] = $out;
	}

	private function isTextCastable(?\ReflectionType $type): bool
	{
		if (null === $type) {
			return false;
		}
		$types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
		foreach ($types as $t) {
			if (!$t instanceof \ReflectionNamedType) {
				return false; // intersections or nested unions are not supported
			}
			$name = $t->getName();
			if ('null' === $name) {
				continue;
			}
			if (!in_array($name, ['string', 'int', 'float', 'bool', \DateTimeInterface::class], true)) {
				return false;
			}
		}

		// Every union member is scalar/date (or null) → text-castable.
		return true;
	}

	/**
	 * To-one association targets of a class (`properties` relation name =>
	 * target class). One level deep only.
	 *
	 * @param class-string $class
	 * @return array<string, class-string>
	 */
	private function relationsOf(string $class): array
	{
		$out = [];
		try {
			$metadata = $this->em->getClassMetadata($class);
			foreach ($metadata->associationMappings as $name => $assoc) {
				$isToOne = (($assoc['type'] ?? null) & ClassMetadata::TO_ONE) !== 0;
				$target = $assoc['targetEntity'] ?? null;
				if ($isToOne && is_string($target) && class_exists($target)) {
					$out[$name] = $target;
				}
			}
		} catch (\Throwable) {
			// Metadata unavailable — no related fields offered.
		}

		return $out;
	}
}
