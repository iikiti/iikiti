<?php

namespace iikiti\CMS\Event;

use Symfony\Contracts\EventDispatcher\Event;

class InitializeEvent extends Event
{
	public const NAME = 'cms.initialize';
}
