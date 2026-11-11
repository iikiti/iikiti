<?php

declare(strict_types=1);

namespace iikiti\CMS\Serializer;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** Adds selected property values while retaining localized source values for editing. */
final class DbObjectLocaleNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	private const NORMALIZED_OBJECTS_CONTEXT = 'iikiti_db_object_locales_normalized';

	private ?NormalizerInterface $normalizer = null;

	public function __construct(
		private readonly LocalizedPropertyResolver $properties,
	) {
	}

	public function setNormalizer(NormalizerInterface $normalizer): void
	{
		$this->normalizer = $normalizer;
	}

	public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
	{
		if (!$data instanceof DbObject || null === $this->normalizer) {
			throw new \LogicException('DbObject locale normalizer was called outside its supported context.');
		}

		$normalizedObjects = is_array($context[self::NORMALIZED_OBJECTS_CONTEXT] ?? null) ? $context[self::NORMALIZED_OBJECTS_CONTEXT] : [];
		$normalizedObjects[spl_object_id($data)] = true;
		$context[self::NORMALIZED_OBJECTS_CONTEXT] = $normalizedObjects;
		$normalized = $this->normalizer->normalize($data, $format, $context);
		if ($normalized instanceof \ArrayObject) {
			$values = $normalized->getArrayCopy();
			$this->resolveProperties($data, $values);
			$normalized->exchangeArray($values);
		} elseif (is_array($normalized)) {
			$this->resolveProperties($data, $normalized);
		}

		return $normalized;
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		if (!$data instanceof DbObject) {
			return false;
		}

		$normalizedObjects = $context[self::NORMALIZED_OBJECTS_CONTEXT] ?? [];

		return !is_array($normalizedObjects) || !isset($normalizedObjects[spl_object_id($data)]);
	}

	public function getSupportedTypes(?string $format): array
	{
		return [DbObject::class => false];
	}

	/** @param array<array-key,mixed> $normalized */
	private function resolveProperties(DbObject $object, array &$normalized): void
	{
		$rows = $normalized['properties'] ?? null;
		if (!is_array($rows)) {
			return;
		}

		foreach ($object->getProperties() as $propertyName => $property) {
			if ($property->isSecret()) {
				continue;
			}
			$resolved = $this->properties->resolve($object, $property);
			if (null === $resolved) {
				continue;
			}

			foreach ($rows as $key => &$row) {
				if (!is_array($row) || (string) ($row['name'] ?? $key) !== (string) $propertyName) {
					continue;
				}
				$row['value'] = $resolved->value;
				$row['locale'] = $resolved->locale?->getTag();
				$row['localizedValue'] = $resolved->localizedValue->jsonSerialize();
				break;
			}
			unset($row);
		}
		$normalized['properties'] = $rows;
	}
}
