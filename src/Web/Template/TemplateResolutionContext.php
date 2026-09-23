<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;

/**
 * Context used by the template resolver to decide which {@see Template} applies.
 */
final class TemplateResolutionContext
{
	public function __construct(
		public readonly ?Site $site = null,
		public readonly ?DbObject $object = null,
		public readonly ?string $objectType = null,
		public readonly bool $home = false,
	) {
	}

	public function objectFqcn(): ?string
	{
		return null === $this->object ? null : $this->object::class;
	}
}
