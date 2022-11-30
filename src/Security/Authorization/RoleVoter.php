<?php
namespace iikiti\CMS\Security\Authorization;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter as SymfonyRoleVoter;

/**
 *
 */
#[Autoconfigure(tags: ['security.access.simple_role_voter'])]
class RoleVoter extends SymfonyRoleVoter {

}
