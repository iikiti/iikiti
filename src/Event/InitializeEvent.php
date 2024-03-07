<?php

namespace iikiti\CMS\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event for CMS's initialization event.
 */
class InitializeEvent extends Event
{
	public const NAME = 'cms.initialize';
}
