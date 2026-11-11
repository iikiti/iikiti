<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Object;

use iikiti\CMS\Entity\Object\User;
use PHPUnit\Framework\TestCase;

final class UserLocaleTest extends TestCase
{
	public function testPreferredLocaleIsCanonicalizedAndStoredInPreferences(): void
	{
		$user = new User();
		$user->setPreferredLocale('fr-ca');

		self::assertSame('fr-CA', $user->getPreferredLocale()?->getTag());
		self::assertSame('fr-CA', $user->getPreferences()->get(User::PREFERRED_LOCALE_KEY));
	}

	public function testPreferredLocaleCanBeCleared(): void
	{
		$user = new User();
		$user->setPreferredLocale('en-GB');
		$user->setPreferredLocale(null);

		self::assertNull($user->getPreferredLocale());
	}

	public function testInvalidPreferredLocaleIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new User())->setPreferredLocale('en_US');
	}
}
