<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

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
    public function __construct(
        ManagerRegistry $registry,
        private SiteRegistry $siteRegistry,
        DatabaseCacheManager $cacheManager
    ) {
        parent::__construct($registry, $siteRegistry, $cacheManager, ApiToken::class);
    }

    /**
     * ApiToken does not extend DbObject and has no site association, so
     * site-scoping must be disabled by default for this repository.
     *
     * @param string $key The option name to look up.
     */
    protected static function _defaultOption(string $key): bool|string|int|null
    {
        if ($key === 'filterBySite') {
            return false;
        }

        return parent::_defaultOption($key);
    }
}
