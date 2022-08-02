<?php

namespace iikiti\CMS\Events;

use Symfony\Contracts\EventDispatcher\Event;

class InitializeEvent extends Event {

    public const NAME = 'cms.initialize';

}
