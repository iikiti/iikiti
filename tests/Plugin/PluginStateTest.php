<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Plugin\PluginState;
use PHPUnit\Framework\TestCase;

final class PluginStateTest extends TestCase
{
	public function testPublishedIsApproved(): void
	{
		$this->assertTrue(PluginState::Published->isApproved());
		$this->assertFalse(PluginState::Testing->isApproved());
	}

	public function testRejectedIsPermanentlyBlocked(): void
	{
		$this->assertTrue(PluginState::Rejected->isPermanentlyBlocked());
		$this->assertFalse(PluginState::Development->isPermanentlyBlocked());
	}

	public function testFromStoreDefaultsToPendingReview(): void
	{
		$this->assertSame(PluginState::PendingReview, PluginState::fromStore(null));
		$this->assertSame(PluginState::PendingReview, PluginState::fromStore(''));
	}

	public function testFromStoreMapsUnknownStateToRejected(): void
	{
		$this->assertSame(PluginState::Rejected, PluginState::fromStore('something-new'));
	}

	public function testFromStoreParsesKnownState(): void
	{
		$this->assertSame(PluginState::Published, PluginState::fromStore('published'));
		$this->assertSame(PluginState::Testing, PluginState::fromStore('testing'));
	}
}
