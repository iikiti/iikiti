<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

/**
 * Thrown when an uploaded SVG is not safe to store. The upload is rejected in full;
 * the sanitiser never repairs a document by silently dropping unsafe parts.
 */
final class SvgRejectedException extends \InvalidArgumentException
{
}
