<?php

namespace iikiti\CMS\EventListeners;

use Closure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Class OutputParser
 *
 * @package iikiti\CMS\src\EventListeners
 */
class OutputParser implements EventSubscriberInterface
{
    protected static array $filters = [];

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.response' => 'onKernelResponse'
        ];
    }

    /**
     * @param \Closure $filter
     *
     * @return void
     */
    public static function appendFilter(Closure $filter)
    {
        self::$filters[] = $filter;
    }

    /**
     * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
     *
     * @return void
     */
    public function onKernelResponse(ResponseEvent $event) {
        foreach (static::$filters as $filter) {
            if (!($filter instanceof Closure)) {
                continue;
            }
            $filter($event);
        }
    }

}
