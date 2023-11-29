<?php

namespace iikiti\CMS\Event;

use Symfony\Component\HttpKernel\Event\RequestEvent as EventRequestEvent;

class RequestEvent extends EventRequestEvent
{
	public const NAME = 'cms.request';
}
