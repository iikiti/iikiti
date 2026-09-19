<?php

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Role;

/**
 * Repository for role entities.
 *
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
	) {
		parent::__construct($registry, Role::class);
	}

	public function findByValue(string $value): ?Role
	{
		/** @var Role|null $result */
		$result = $this->findOneBy(['value' => $value]);

		return $result;
	}

	/**
	 * @return array<Role>
	 */
	public function findAllVisible(): array
	{
		return $this->findBy(['isHidden' => false], ['name' => 'ASC']);
	}

	/**
	 * @return array<Role>
	 */
	public function findDeletable(): array
	{
		return $this->findBy(['isDeletable' => true]);
	}
}
