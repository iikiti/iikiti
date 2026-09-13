<?php

namespace iikiti\CMS\Authentication\Exception;

use Symfony\Component\Security\Core\Exception\AccessDeniedException as SecurityAccessDeniedException;

/**
 * Thrown when a multi-factor challenge cannot be completed.
 */
class AccessDeniedException extends SecurityAccessDeniedException
{
}
