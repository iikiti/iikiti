<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Repository for API token entities.
 *
 * @template-extends ObjectRepository<ApiToken>
 * @method ApiToken|null find($id, $lockMode = null, $lockVersion = null, array<string,mixed> $options = [])
 * @method ApiToken|null findOneBy(array<string,mixed> $criteria, ?array<string,mixed> $orderBy = null, array<string,mixed> $options = [])
 * @method ApiToken[]    findAll(array<string,mixed> $options = [])
 * @method ApiToken[]    findBy(array<string,mixed> $criteria, ?array<string,mixed> $orderBy = null, $limit = null, $offset = null, array<string,mixed> $options = [])
 */
class ApiTokenRepository extends ObjectRepository
{
    public function __construct(ManagerRegistry $registry, private SiteRegistry $siteRegistry)
    {
        parent::__construct($registry, $siteRegistry, ApiToken::class);
    }
}