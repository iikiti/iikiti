<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

/**
 * Marker interface for pure-static, zero-I/O fragment providers whose output
 * may be rendered inline during page manifest generation rather than deferred
 * to a separate HTTP subrequest.
 *
 * Inlineable providers remain registered in {@see FragmentProviderRegistry}
 * and stay callable via the HTTP and JS Fragment APIs.
 */
interface InlineableFragmentProviderInterface extends FragmentProviderInterface
{
}
