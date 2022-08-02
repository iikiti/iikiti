<?php

namespace iikiti\CMS\Events;

use Symfony\Component\HttpKernel\Event\RequestEvent as EventRequestEvent;

class RequestEvent extends EventRequestEvent {

    public const NAME = 'cms.request';

}
