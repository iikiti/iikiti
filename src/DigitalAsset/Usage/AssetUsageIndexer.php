<?php

declare(strict_types=1);

namespace iikiti\CMS\DigitalAsset\Usage;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\DigitalAssetUsage;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetUsageRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Maintains and queries the `digital_asset_usages` projection table using
 * pluggable {@see AssetUsageExtractorInterface} extractors.
 */
final class AssetUsageIndexer implements AssetUsageIndexInterface
{
	/** @var list<string> */
	public const TRACKED_PROPERTY_NAMES = [
		'blocks',
		'blocks_draft',
		'dynamic_blocks',
		'dynamic_blocks_draft',
		'settings',
		'settings_draft',
		'configuration',
	];

	/** @var list<AssetUsageExtractorInterface> */
	private readonly array $extractors;

	/** @var array<string, ?int> */
	private array $tokenCache = [];

	/**
	 * @param iterable<AssetUsageExtractorInterface> $extractors
	 */
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetUsageRepository $usages,
		private readonly DigitalAssetRepository $assets,
		#[AutowireIterator('iikiti.asset_usage_extractor')]
		iterable $extractors = [],
	) {
		$resolved = [];
		foreach ($extractors as $extractor) {
			$resolved[] = $extractor;
		}
		if ([] === $resolved) {
			$resolved = [
				new BlockTreeAssetUsageExtractor(),
				new SettingsAssetUsageExtractor(),
			];
		}
		$this->extractors = $resolved;
	}

	/**
	 * @return list<AssetUsageReference>
	 */
	#[\Override]
	public function findUsage(int|string $assetId): array
	{
		$rows = $this->usages->findByAssetId($assetId);
		if ([] === $rows) {
			return [];
		}

		$contextTitles = $this->loadContextTitles($rows);
		$siteNames = $this->loadSiteNames($rows);

		$references = [];
		foreach ($rows as $row) {
			$contextType = $row->getContextType();
			$contextId = (int) $row->getContextId();
			$siteId = (int) $row->getSiteId();
			$titleKey = $contextType.':'.$contextId;

			$references[] = new AssetUsageReference(
				contextType: $contextType,
				contextId: $contextId,
				contextTitle: $contextTitles[$titleKey] ?? sprintf('%s #%d', ucfirst($contextType), $contextId),
				fieldPath: $row->getFieldPath(),
				isDraft: $row->isDraft(),
				siteId: $siteId,
				editUrl: $this->buildEditUrl($contextType, $contextId),
				siteName: $siteId > 0 ? ($siteNames[(string) $siteId] ?? null) : null,
			);
		}

		return $references;
	}

	/**
	 * @param list<int|string> $assetIds
	 *
	 * @return array<string, int>
	 */
	#[\Override]
	public function countUsageByAssetIds(array $assetIds): array
	{
		return $this->usages->countByAssetIds($assetIds);
	}

	#[\Override]
	public function reindexContext(string $contextType, int|string $contextId): void
	{
		$normalizedType = strtolower(trim($contextType));
		$entity = $this->loadContextEntity($normalizedType, $contextId);
		if (!$entity instanceof DbObject) {
			$this->usages->deleteForContext($normalizedType, $contextId);

			return;
		}

		$this->tokenCache = [];
		$extracted = $this->extractForEntity($entity, $normalizedType);
		$this->usages->replaceContextUsages($normalizedType, $contextId, $extracted);
	}

	#[\Override]
	public function rebuildAll(): int
	{
		$this->tokenCache = [];
		$this->usages->deleteAll();

		$indexedContexts = 0;

		foreach ($this->entityManager->getRepository(Template::class)->findAll() as $template) {
			$id = $template->getId();
			if (null === $id) {
				continue;
			}
			$this->usages->replaceContextUsages('template', $id, $this->extractForEntity($template, 'template'));
			++$indexedContexts;
		}

		foreach ($this->entityManager->getRepository(Site::class)->findAll() as $site) {
			$id = $site->getId();
			if (null === $id) {
				continue;
			}
			$this->usages->replaceContextUsages('site', $id, $this->extractForEntity($site, 'site'));
			++$indexedContexts;
		}

		foreach ($this->entityManager->getRepository(Application::class)->findAll() as $application) {
			$id = $application->getId();
			if (null === $id) {
				continue;
			}
			$this->usages->replaceContextUsages('application', $id, $this->extractForEntity($application, 'application'));
			++$indexedContexts;
		}

		/** @var array<string, DbObject> $pageObjects */
		$pageObjects = [];
		foreach ($this->entityManager->getRepository(Page::class)->findAll() as $page) {
			$id = $page->getId();
			if (null !== $id) {
				$pageObjects[(string) $id] = $page;
			}
		}

		foreach (
			$this->entityManager->getRepository(ObjectProperty::class)->findBy([
				'name' => ['dynamic_blocks', 'dynamic_blocks_draft', 'settings', 'settings_draft'],
			]) as $property
		) {
			$owner = $property->getObject();
			if (!$owner instanceof DbObject) {
				continue;
			}
			if ($owner instanceof Template || $owner instanceof Site || $owner instanceof Application) {
				continue;
			}
			$ownerId = $owner->getId();
			if (null !== $ownerId) {
				$pageObjects[(string) $ownerId] = $owner;
			}
		}

		foreach ($pageObjects as $object) {
			$id = $object->getId();
			if (null === $id) {
				continue;
			}
			$contextType = self::contextTypeForObject($object);
			$this->usages->replaceContextUsages($contextType, $id, $this->extractForEntity($object, $contextType));
			++$indexedContexts;
		}

		return $indexedContexts;
	}

	public function deleteContext(string $contextType, int|string $contextId): void
	{
		$this->usages->deleteForContext($contextType, $contextId);
	}

	public static function contextTypeForObject(DbObject $object): string
	{
		if ($object instanceof Template) {
			return 'template';
		}
		if ($object instanceof Site) {
			return 'site';
		}
		if ($object instanceof Application) {
			return 'application';
		}
		if ($object instanceof Page) {
			return 'page';
		}

		return 'plugin';
	}

	/**
	 * Extracts all resolved asset usage rows for the given CMS object.
	 *
	 * @return list<array{assetId: int, fieldPath: string, isDraft: bool, siteId: int}>
	 */
	public function extractForEntity(DbObject $entity, string $contextType): array
	{
		$siteId = $entity instanceof Site
			? (int) ($entity->getId() ?? 0)
			: (int) $entity->getSiteId();

		$propertySpecs = match ($contextType) {
			'template' => [
				['name' => 'blocks', 'isDraft' => false],
				['name' => 'blocks_draft', 'isDraft' => true],
				['name' => 'settings', 'isDraft' => false],
				['name' => 'settings_draft', 'isDraft' => true],
			],
			'site', 'application' => [
				['name' => 'configuration', 'isDraft' => false],
				['name' => 'settings', 'isDraft' => false],
				['name' => 'settings_draft', 'isDraft' => true],
			],
			default => [
				['name' => 'dynamic_blocks', 'isDraft' => false],
				['name' => 'dynamic_blocks_draft', 'isDraft' => true],
				['name' => 'settings', 'isDraft' => false],
				['name' => 'settings_draft', 'isDraft' => true],
			],
		};

		$rows = [];
		$seen = [];
		$properties = $entity->getProperties();

		foreach ($propertySpecs as $spec) {
			$prop = $properties->get($spec['name']);
			if (!$prop instanceof ObjectProperty) {
				continue;
			}
			$value = $prop->getValue();
			if (null === $value || '' === $value || [] === $value) {
				continue;
			}

			foreach ($this->extractors as $extractor) {
				foreach ($extractor->extractFromValue($value, $spec['name']) as $match) {
					$assetId = $this->resolveMatchAssetId($match);
					if (null === $assetId) {
						continue;
					}
					$fieldPath = mb_substr(trim($match['fieldPath']), 0, 255);
					$dedupeKey = $assetId.'|'.$fieldPath.'|'.($spec['isDraft'] ? '1' : '0');
					if (isset($seen[$dedupeKey])) {
						continue;
					}
					$seen[$dedupeKey] = true;
					$rows[] = [
						'assetId' => $assetId,
						'fieldPath' => $fieldPath,
						'isDraft' => $spec['isDraft'],
						'siteId' => $siteId,
					];
				}
			}
		}

		return $rows;
	}

	/**
	 * @param array{assetId?: int, publicToken?: string, fieldPath: string} $match
	 */
	private function resolveMatchAssetId(array $match): ?int
	{
		if (isset($match['assetId']) && $match['assetId'] > 0) {
			return $match['assetId'];
		}

		$token = isset($match['publicToken']) ? strtolower(trim($match['publicToken'])) : '';
		if ('' === $token) {
			return null;
		}

		if (array_key_exists($token, $this->tokenCache)) {
			return $this->tokenCache[$token];
		}

		$asset = $this->assets->findByPublicToken($token);
		$resolvedId = $asset instanceof AbstractDigitalAsset && null !== $asset->getId()
			? (int) $asset->getId()
			: null;
		$this->tokenCache[$token] = $resolvedId;

		return $resolvedId;
	}

	private function loadContextEntity(string $contextType, int|string $contextId): ?DbObject
	{
		$class = match ($contextType) {
			'template' => Template::class,
			'site' => Site::class,
			'application' => Application::class,
			'page' => Page::class,
			default => DbObject::class,
		};

		$entity = $this->entityManager->find($class, $contextId);
		if ($entity instanceof DbObject) {
			return $entity;
		}

		if (DbObject::class !== $class) {
			$fallback = $this->entityManager->find(DbObject::class, $contextId);

			return $fallback instanceof DbObject ? $fallback : null;
		}

		return null;
	}

	/**
	 * @param list<DigitalAssetUsage> $rows
	 *
	 * @return array<string, string>
	 */
	private function loadContextTitles(array $rows): array
	{
		$titles = [];
		$checked = [];
		foreach ($rows as $row) {
			$contextType = $row->getContextType();
			$contextId = (int) $row->getContextId();
			$key = $contextType.':'.$contextId;
			if (isset($checked[$key])) {
				continue;
			}
			$checked[$key] = true;

			$entity = $this->loadContextEntity($contextType, $contextId);
			if (!$entity instanceof DbObject) {
				continue;
			}

			$title = $this->extractDisplayTitle($entity, $contextType, $contextId);
			if (null !== $title) {
				$titles[$key] = $title;
			}
		}

		return $titles;
	}

	/**
	 * @param list<DigitalAssetUsage> $rows
	 *
	 * @return array<string, string>
	 */
	private function loadSiteNames(array $rows): array
	{
		$siteNames = [];
		$checked = [];
		foreach ($rows as $row) {
			$siteId = (int) $row->getSiteId();
			if ($siteId < 1) {
				continue;
			}
			$key = (string) $siteId;
			if (isset($checked[$key])) {
				continue;
			}
			$checked[$key] = true;

			$site = $this->entityManager->find(Site::class, $siteId);
			if (!$site instanceof Site) {
				continue;
			}
			$props = $site->getProperties();
			$name = $props->get('name')?->getValue() ?? $props->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();
			if (is_string($name) && '' !== trim($name)) {
				$siteNames[$key] = trim($name);
			}
		}

		return $siteNames;
	}

	private function extractDisplayTitle(DbObject $entity, string $contextType, int $contextId): ?string
	{
		$props = $entity->getProperties();
		foreach (['title', 'name', Site::DOMAIN_PROPERTY_KEY, 'slug'] as $propName) {
			$val = $props->get($propName)?->getValue();
			if (is_string($val) && '' !== trim($val)) {
				return trim($val);
			}
			if (is_array($val) && isset($val['translations']) && is_array($val['translations'])) {
				$first = reset($val['translations']);
				if (is_string($first) && '' !== trim($first)) {
					return trim($first);
				}
			}
		}

		return sprintf('%s #%d', ucfirst($contextType), $contextId);
	}

	private function buildEditUrl(string $contextType, int $contextId): ?string
	{
		return match ($contextType) {
			'page' => sprintf('/admin#/pages/%d', $contextId),
			'template' => sprintf('/admin#/templates/%d', $contextId),
			'site' => sprintf('/admin#/sites/%d', $contextId),
			'application' => sprintf('/admin#/applications/%d', $contextId),
			'plugin' => '/admin#/plugins',
			default => null,
		};
	}
}
