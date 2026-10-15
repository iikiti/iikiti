<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Repository\Workflow\WorkflowDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkflowSiteAssignmentMappingTest extends KernelTestCase
{
	private EntityManagerInterface $entityManager;

	protected function setUp(): void
	{
		self::bootKernel();
		$this->entityManager = self::getContainer()->get('doctrine')->getManager();
		$this->entityManager->getConnection()->beginTransaction();
	}

	protected function tearDown(): void
	{
		if (isset($this->entityManager) && $this->entityManager->getConnection()->isTransactionActive()) {
			$this->entityManager->getConnection()->rollBack();
			$this->entityManager->clear();
		}
		parent::tearDown();
	}

	public function testWorkflowDefinitionSiteAssignmentsRoundTripThroughTheMappedEntity(): void
	{
		$sites = $this->entityManager->getRepository(Site::class)->findAll(['filterBySite' => false]);
		if ([] === $sites) {
			self::markTestSkipped('A local site is required to exercise workflow site assignment.');
		}
		$site = $sites[0];
		self::assertInstanceOf(Site::class, $site);
		$definition = new WorkflowDefinition('integration.'.bin2hex(random_bytes(4)), 'Workflow assignment', 'site', (string) $site->getId());
		$definition->assignToSite($site);
		$this->entityManager->persist($definition);
		$this->entityManager->flush();
		$definitionId = $definition->getId();
		$this->entityManager->clear();

		$reloadedSite = $this->entityManager->find(Site::class, $site->getId());
		$reloadedDefinition = $this->entityManager->find(WorkflowDefinition::class, $definitionId);
		self::assertInstanceOf(Site::class, $reloadedSite);
		self::assertInstanceOf(WorkflowDefinition::class, $reloadedDefinition);
		self::assertTrue($reloadedDefinition->hasSite($reloadedSite));

		$repository = $this->entityManager->getRepository(WorkflowDefinition::class);
		self::assertInstanceOf(WorkflowDefinitionRepository::class, $repository);
		self::assertContains($definitionId, array_map(
			static fn (WorkflowDefinition $candidate): int|string|null => $candidate->getId(),
			$repository->findAssignedToSite($reloadedSite),
		));
	}
}
