<?php

namespace iikiti\CMS\Security\Authentication\MultiFactor;

use Symfony\Component\Security\Core\Exception\AccessDeniedException as SecurityAccessDeniedException;

class AccessDeniedException extends SecurityAccessDeniedException
{
}
