<?php

declare(strict_types=1);

namespace iikiti\CMS\Serializer;

use iikiti\CMS\Entity\DbObject;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** Removes secret properties from normal DbObject representations. */
final class DbObjectSecretNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	private const NORMALIZED_OBJECTS_CONTEXT = 'iikiti_db_object_secrets_normalized';

	private ?NormalizerInterface $normalizer = null;

	public function setNormalizer(NormalizerInterface $normalizer): void
	{
		$this->normalizer = $normalizer;
	}

	public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
	{
		if (!$data instanceof DbObject || null === $this->normalizer) {
			throw new \LogicException('DbObject secret normalizer was called outside its supported context.');
		}

		$normalizedObjects = is_array($context[self::NORMALIZED_OBJECTS_CONTEXT] ?? null) ? $context[self::NORMALIZED_OBJECTS_CONTEXT] : [];
		$normalizedObjects[spl_object_id($data)] = true;
		$context[self::NORMALIZED_OBJECTS_CONTEXT] = $normalizedObjects;
		$normalized = $this->normalizer->normalize($data, $format, $context);
		$secretNames = [];
		foreach ($data->getProperties() as $name => $property) {
			if ($property->isSecret()) {
				$secretNames[(string) $name] = true;
			}
		}

		if ([] === $secretNames) {
			return $normalized;
		}
		if (is_array($normalized)) {
			$this->removeSecrets($normalized, $secretNames);
		} elseif ($normalized instanceof \ArrayObject) {
			$values = $normalized->getArrayCopy();
			$this->removeSecrets($values, $secretNames);
			$normalized->exchangeArray($values);
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

	/**
	 * @param array<array-key,mixed> $normalized
	 * @param array<string,bool>     $secretNames
	 */
	private function removeSecrets(array &$normalized, array $secretNames): void
	{
		$properties = $normalized['properties'] ?? null;
		if (!is_array($properties)) {
			return;
		}

		foreach ($properties as $key => $property) {
			if (isset($secretNames[(string) $key]) || (is_array($property) && is_string($property['name'] ?? null) && isset($secretNames[$property['name']]))) {
				unset($properties[$key]);
			}
		}
		$normalized['properties'] = $properties;
	}
}
