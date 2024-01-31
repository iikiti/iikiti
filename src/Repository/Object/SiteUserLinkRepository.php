<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\SiteUserLink;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;

class SiteUserLinkRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		string $entityClass = SiteUserLink::class
	) {
		parent::__construct($registry, $siteRegistry, $entityClass);
	}

	public function getUser(SiteUserLink $userLink): ?User
	{
		return $this->getEntityManager()->getRepository(User::class)->find($userLink->getUserId());
	}

	public function findByUserId(string|int $userId): ?SiteUserLink
	{
		return $this->findByMetaValue(
			'JSON_CONTAINS('.
				'meta.json_content, '.
				(is_int($userId) ? ':json_value' : 'JSON_QUOTE(:json_value)').
					','.
				'\'$.userId\''.
			')',
			1,
			true,
			['json_value' => $userId]
		);
	}
}
