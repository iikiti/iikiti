<?php

namespace iikiti\CMS\Event;

use Symfony\Component\HttpKernel\Event\RequestEvent as EventRequestEvent;

/**
 * Event for CMS's request event.
 */
class RequestEvent extends EventRequestEvent
{
	public const NAME = 'cms.request';
}
