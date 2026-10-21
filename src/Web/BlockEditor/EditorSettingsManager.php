<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Template;

/**
 * Draft and published settings stored on Page and Template object properties.
 */
final class EditorSettingsManager
{
	public function __construct(private readonly EntityManagerInterface $entityManager)
	{
	}

	/**
	 * @return array{values:array<string,mixed>,version:int,hasDraft:bool}
	 */
	public function state(DbObject $object): array
	{
		$properties = $object->getProperties();
		$published = $properties->get('settings')?->getValue();
		$draft = $properties->get('settings_draft')?->getValue();
		$version = $properties->get('settings_version')?->getValue();

		return [
			'values' => is_array($draft) ? $draft : (is_array($published) ? $published : []),
			'version' => is_numeric($version) ? (int) $version : 0,
			'hasDraft' => is_array($draft),
		];
	}

	/** @return array<string,mixed> */
	public function publishedSettings(DbObject $object): array
	{
		$value = $object->getProperties()->get('settings')?->getValue();

		return is_array($value) ? $value : [];
	}

	public function findTarget(string $targetType, int $targetId): ?DbObject
	{
		$class = match ($targetType) {
			'page' => Page::class,
			'template' => Template::class,
			default => null,
		};
		if (null === $class) {
			return null;
		}

		$target = $this->entityManager->getRepository($class)->find($targetId);

		return match ($targetType) {
			'page' => $target instanceof Page ? $target : null,
			'template' => $target instanceof Template ? $target : null,
			default => null,
		};
	}

	/**
	 * @param array<string,mixed> $values
	 *
	 * @return array{ok:bool,version:int,conflict:bool}
	 */
	public function save(DbObject $target, array $values, ?string $ifMatchVersion): array
	{
		$version = $this->version($target);
		if (null !== $ifMatchVersion && (string) $version !== $ifMatchVersion) {
			return ['ok' => false, 'version' => $version, 'conflict' => true];
		}

		$target->setProperty('settings_draft', $values);
		$newVersion = $version + 1;
		$target->setProperty('settings_version', $newVersion);
		$this->entityManager->persist($target);
		$this->entityManager->flush();

		return ['ok' => true, 'version' => $newVersion, 'conflict' => false];
	}

	/**
	 * @return array{ok:bool,version:int,conflict:bool,hasDraft:bool}
	 */
	public function publish(DbObject $target, ?string $ifMatchVersion): array
	{
		$version = $this->version($target);
		if (null !== $ifMatchVersion && (string) $version !== $ifMatchVersion) {
			return ['ok' => false, 'version' => $version, 'conflict' => true, 'hasDraft' => is_array($target->getProperties()->get('settings_draft')?->getValue())];
		}

		$draft = $target->getProperties()->get('settings_draft')?->getValue();
		if (!is_array($draft)) {
			return ['ok' => true, 'version' => $version, 'conflict' => false, 'hasDraft' => false];
		}

		$target->setProperty('settings', $draft);
		$target->getProperties()->remove('settings_draft');
		$newVersion = $version + 1;
		$target->setProperty('settings_version', $newVersion);
		$this->entityManager->persist($target);
		$this->entityManager->flush();

		return ['ok' => true, 'version' => $newVersion, 'conflict' => false, 'hasDraft' => false];
	}

	private function version(DbObject $target): int
	{
		$value = $target->getProperties()->get('settings_version')?->getValue();

		return is_numeric($value) ? (int) $value : 0;
	}
}
