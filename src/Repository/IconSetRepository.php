<?php

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\IconSetEntity;

/**
 * @extends ServiceEntityRepository<IconSetEntity>
 */
class IconSetRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, IconSetEntity::class);
	}

	public function findOneBySlug(string $slug): ?IconSetEntity
	{
		return $this->findOneBy(['slug' => $slug]);
	}
}
