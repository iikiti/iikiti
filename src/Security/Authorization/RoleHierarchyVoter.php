<?php
namespace iikiti\CMS\Security\Authorization;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use \Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter as SymfonyRoleHierarchyVoter;

/**
 *
 */
#[Autoconfigure(tags: ['security.access.role_hierarchy_voter'])]
class RoleHierarchyVoter extends SymfonyRoleHierarchyVoter {

}
