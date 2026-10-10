<?php

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\IconEntity;
use iikiti\CMS\Entity\IconSetEntity;

/**
 * @extends ServiceEntityRepository<IconEntity>
 */
class IconRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, IconEntity::class);
	}

	/**
	 * @return list<IconEntity>
	 */
	public function findBySet(IconSetEntity $set): array
	{
		/** @var list<IconEntity> */
		return $this->findBy(['iconSet' => $set], ['name' => 'ASC']);
	}

	public function findOneInSet(IconSetEntity $set, string $name): ?IconEntity
	{
		return $this->findOneBy(['iconSet' => $set, 'name' => $name]);
	}
}
