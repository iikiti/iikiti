<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Default workflow: autosave writes a draft + bumps a draft version; publish
 * copies draft -> published and bumps it. Uses the object's JSON properties
 * (`blocks`/`blocks_draft` for templates; `dynamic_blocks`/`dynamic_blocks_draft`
 * for objects).
 */
#[AutoconfigureTag('iikiti.cms.save_workflow')]
final class DraftPublishWorkflow implements SaveWorkflowInterface
{
	public function __construct(private readonly EntityManagerInterface $em)
	{
	}

	public function getName(): string
	{
		return 'draft';
	}

	#[\Override]
	public function save(string $contextType, int $contextId, array $tree, ?string $ifMatchVersion, User $user): array
	{
		$object = $this->load($this->em, $contextType, $contextId);
		if (!$object instanceof DbObject) {
			return ['ok' => false, 'version' => 0, 'conflict' => false];
		}

		$version = $this->currentVersion($object, $contextType);
		if (null !== $ifMatchVersion && (string) $version !== $ifMatchVersion) {
			return ['ok' => false, 'version' => $version, 'conflict' => true];
		}

		[$draftKey, $publishedKey] = $this->propertyKeys($contextType);
		$object->setProperty($draftKey, $tree);
		$newVersion = $version + 1;
		$object->setProperty($this->versionKey($contextType), $newVersion);
		$this->em->persist($object);
		$this->em->flush();

		return ['ok' => true, 'version' => $newVersion, 'conflict' => false];
	}

	#[\Override]
	public function publish(string $contextType, int $contextId, User $user): array
	{
		$object = $this->load($this->em, $contextType, $contextId);
		if (!$object instanceof DbObject) {
			return ['ok' => false];
		}

		[$draftKey, $publishedKey] = $this->propertyKeys($contextType);
		$draft = $object->getProperties()->get($draftKey)?->getValue();
		$object->setProperty($publishedKey, is_array($draft) ? $draft : ($object->getProperties()->get($publishedKey)?->getValue() ?? []));
		$version = $this->currentVersion($object, $contextType) + 1;
		$object->setProperty($this->versionKey($contextType), $version);
		$this->em->persist($object);
		$this->em->flush();

		return ['ok' => true];
	}

	private function load(EntityManagerInterface $em, string $contextType, int $contextId): ?DbObject
	{
		$class = match ($contextType) {
			'template' => Template::class,
			'object' => DbObject::class,
			default => null,
		};
		if (null === $class) {
			return null;
		}

		$repo = $em->getRepository($class);
		$object = $repo->find($contextId);

		return $object instanceof DbObject ? $object : null;
	}

	private function currentVersion(DbObject $object, string $contextType): int
	{
		$value = $object->getProperties()->get($this->versionKey($contextType))?->getValue();

		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * @return array{0:string,1:string} [draft, published] property keys
	 */
	private function propertyKeys(string $contextType): array
	{
		return 'template' === $contextType ?
			['blocks_draft', 'blocks'] :
			['dynamic_blocks_draft', 'dynamic_blocks'];
	}

	private function versionKey(string $contextType): string
	{
		return 'template' === $contextType ? 'draft_version' : 'dynamic_version';
	}
}
