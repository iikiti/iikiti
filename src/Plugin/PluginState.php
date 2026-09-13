<?php

namespace iikiti\CMS\Plugin;

/**
 * Review state of a plugin as reported by the store.
 *
 * The store performs code review (automated and/or manual) and communicates the
 * outcome via this state. The CMS only enforces the outcome — it never performs
 * code review itself.
 */
enum PluginState: string
{
	/** Fully reviewed and approved for production use. */
	case Published = 'published';

	/** Submitted, awaiting code review. */
	case PendingReview = 'pending_review';

	/** Passed automated review, in a testing phase. */
	case Testing = 'testing';

	/** Unstable / work in progress. */
	case Development = 'development';

	/** Review failed or plugin denied. */
	case Rejected = 'rejected';

	/**
	 * Whether the plugin may be installed without an explicit override.
	 */
	public function isApproved(): bool
	{
		return self::Published === $this;
	}

	/**
	 * Whether the state is permanently blocked (an override must never allow it).
	 */
	public function isPermanentlyBlocked(): bool
	{
		return self::Rejected === $this;
	}

	/**
	 * Parse a store-provided state string, defaulting to the safest value.
	 */
	public static function fromStore(?string $state): self
	{
		if (null === $state || '' === $state) {
			return self::PendingReview;
		}

		return self::tryFrom($state) ?? self::Rejected;
	}
}
