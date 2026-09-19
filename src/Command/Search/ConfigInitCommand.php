<?php

namespace iikiti\CMS\Command\Search;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Entity\SearchIndexField;
use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use iikiti\CMS\Search\Enum\SearchIndexType;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds default search index configurations.
 */
#[AsCommand('iikiti:search:config:init', description: 'Create default search index configurations')]
final class ConfigInitCommand extends AbstractSearchCommand
{
	public function __construct(
		SearchIndexRepository $indexRepository,
		EntityManagerInterface $entityManager,
		SearchEngineRegistry $engineRegistry,
	) {
		parent::__construct($indexRepository, $engineRegistry, $entityManager);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		// Check if frontend index already exists
		$existing = $this->indexRepository->findBySlug('frontend');
		if (null !== $existing) {
			$io->warning('Default frontend search index already exists. Use --force to recreate.');

			$existingAdmin = $this->indexRepository->findBySlug('admin');
			if (null !== $existingAdmin && !$existing->isSystemLocked()) {
				if (!$io->confirm('Recreate default indexes?', false)) {
					return Command::SUCCESS;
				}
			}
		}

		$this->entityManager->getConnection()->beginTransaction();

		try {
			$this->createFrontendIndex();
			$this->createAdminIndex();
			$this->createDefaultConfigGroup();

			$this->entityManager->getConnection()->commit();
			$io->success('Default search configurations created successfully.');
		} catch (\Throwable $e) {
			$this->entityManager->getConnection()->rollBack();
			$io->error('Failed to create default configurations: '.$e->getMessage());

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	private function createFrontendIndex(): void
	{
		$index = new SearchIndex(
			'frontend',
			'Front-end Search',
			SearchIndexType::Frontend,
			'postgresql',
			'english',
		);
		$index->setDescription('Default front-end search index for public site search.');
		$index->setSystemLocked(false);

		$fields = [
			['title', SearchFieldSourceType::Property, 'title', 4],
			['content', SearchFieldSourceType::Property, 'content', 3],
			['body', SearchFieldSourceType::Property, 'body', 3],
			['excerpt', SearchFieldSourceType::Property, 'excerpt', 2],
			['tags', SearchFieldSourceType::Property, 'tags', 2],
			['slug', SearchFieldSourceType::Property, 'slug', 1],
			['type', SearchFieldSourceType::Column, 'type', 1],
		];

		$position = 1;
		foreach ($fields as [$name, $sourceType, $source, $weight]) {
			$field = new SearchIndexField($name, $sourceType, $source);
			$field->setWeight($weight);
			$field->setPosition($position);
			$index->addField($field);
			++$position;
		}

		$this->entityManager->persist($index);
	}

	private function createAdminIndex(): void
	{
		$index = new SearchIndex(
			'admin',
			'Administration Search',
			SearchIndexType::Admin,
			'postgresql',
			'english',
		);
		$index->setDescription('System-level administration search index. System-locked: cannot be deleted, but fields can be managed.');
		$index->setSystemLocked(true);

		$fields = [
			['title', SearchFieldSourceType::Property, 'title', 4],
			['content', SearchFieldSourceType::Property, 'content', 3],
			['body', SearchFieldSourceType::Property, 'body', 3],
			['excerpt', SearchFieldSourceType::Property, 'excerpt', 2],
			['tags', SearchFieldSourceType::Property, 'tags', 2],
			['slug', SearchFieldSourceType::Property, 'slug', 1],
			['type', SearchFieldSourceType::Column, 'type', 2],
			['id', SearchFieldSourceType::Column, 'id', 1],
			['created_date', SearchFieldSourceType::Column, 'created_date', 1],
			['keywords', SearchFieldSourceType::Property, 'keywords', 2],
			['meta_description', SearchFieldSourceType::Property, 'meta_description', 2],
		];

		$position = 1;
		foreach ($fields as [$name, $sourceType, $source, $weight]) {
			$field = new SearchIndexField($name, $sourceType, $source);
			$field->setWeight($weight);
			$field->setPosition($position);
			$index->addField($field);
			++$position;
		}

		$this->entityManager->persist($index);
	}

	private function createDefaultConfigGroup(): void
	{
		$frontend = $this->indexRepository->findBySlug('frontend');
		$admin = $this->indexRepository->findBySlug('admin');

		if (null === $frontend || null === $admin) {
			return;
		}

		$group = new \iikiti\CMS\Search\Entity\SearchConfigGroup('default', 'Default Search Configuration');
		$group->setDescription('Default search configuration group containing frontend and admin search indexes.');
		$group->setSystem(true);
		$group->setSearchIndexIds([$frontend->getId(), $admin->getId()]);

		$this->entityManager->persist($group);

		$siteGroup = new \iikiti\CMS\Search\Entity\SiteGroup('default', 'All Sites');
		$siteGroup->setDescription('Default site group containing all sites.');
		$siteGroup->setSystem(true);

		$this->entityManager->persist($siteGroup);
	}
}
