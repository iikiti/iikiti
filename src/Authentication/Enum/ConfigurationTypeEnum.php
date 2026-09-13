<?php

namespace iikiti\CMS\Authentication\Enum;

/**
 * Level at which a multi-factor preference is configured.
 *
 * Higher levels (application) are overridden by lower levels (user), so user
 * preferences always win.
 */
enum ConfigurationTypeEnum
{
	case APPLICATION;

	case SITE;

	case USER;
}
