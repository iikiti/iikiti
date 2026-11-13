<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

/** Page-only identity metadata paired with the shared fragment payload type. */
final readonly class PageFragmentResult
{
	/** @param array{id:string,revision:string,found?:bool} $metadata */
	public function __construct(
		public FragmentResult $fragment,
		public array $metadata,
	) {
	}
}
