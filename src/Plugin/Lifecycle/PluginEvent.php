<?php

namespace iikiti\CMS\Plugin\Lifecycle;

use iikiti\CMS\Plugin\PluginContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event payload for plugin lifecycle transitions.
 */
class PluginEvent extends Event
{
	public function __construct(
		private readonly PluginContext $context,
		private readonly string $eventName = '',
		private readonly ?string $fromVersion = null,
	) {
	}

	public function getContext(): PluginContext
	{
		return $this->context;
	}

	public function getEventName(): string
	{
		return $this->eventName;
	}

	public function getFromVersion(): ?string
	{
		return $this->fromVersion;
	}

	public function getSlug(): string
	{
		return $this->context->slug;
	}

	public function getVersion(): string
	{
		return $this->context->version;
	}

	public function getSiteId(): ?string
	{
		return $this->context->siteId;
	}
}
