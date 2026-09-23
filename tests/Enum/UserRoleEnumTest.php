<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Enum;

use iikiti\CMS\Enum\UserRoleEnum;
use PHPUnit\Framework\TestCase;

final class UserRoleEnumTest extends TestCase
{
	public function testExistingRolesAreRegistered(): void
	{
		self::assertTrue(UserRoleEnum::has('ROLE_USER'));
		self::assertTrue(UserRoleEnum::has('ROLE_ADMIN'));
		self::assertTrue(UserRoleEnum::has('ROLE_SYSTEM'));
		self::assertTrue(UserRoleEnum::has('ROLE_NON_MEMBER'));
		self::assertTrue(UserRoleEnum::has('ROLE_SUPER_ADMIN'));
	}

	public function testHasReturnsFalseForUnknownRole(): void
	{
		self::assertFalse(UserRoleEnum::has('ROLE_NONEXISTENT'));
		self::assertFalse(UserRoleEnum::has('SOME_OTHER_ROLE'));
	}

	public function testHasNameWorksWithNames(): void
	{
		self::assertTrue(UserRoleEnum::hasName('Admin'));
		self::assertTrue(UserRoleEnum::hasName('Non-Member'));
		self::assertFalse(UserRoleEnum::hasName('Nonexistent'));
	}

	public function testRegisterValidRole(): void
	{
		UserRoleEnum::register('Test Role', 'ROLE_TEST_ROLE');

		self::assertTrue(UserRoleEnum::has('ROLE_TEST_ROLE'));
		self::assertTrue(UserRoleEnum::hasName('Test Role'));

		// Clean up is not needed — the static registry persists for the test session
		// but subsequent registrations with the same value would throw.
	}

	public function testRegisterRejectsLowercaseRole(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('uppercase');

		UserRoleEnum::register('Bad Role', 'role_bad_role');
	}

	public function testRegisterRejectsRoleWithoutPrefix(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ROLE_');

		UserRoleEnum::register('Bad Role', 'ADMIN');
	}

	public function testRegisterRejectsRoleWithWhitespace(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		UserRoleEnum::register('Bad Role', 'ROLE_BAD ROLE');
	}

	public function testRegisterRejectsDuplicateValue(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('already exists');

		UserRoleEnum::register('Another Admin', 'ROLE_ADMIN');
	}

	public function testRegisterRejectsDuplicateName(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('already exists');

		UserRoleEnum::register('Admin', 'ROLE_ADMIN_ALT');
	}

	public function testGetDefaultRolesIncludesUserRole(): void
	{
		$defaults = UserRoleEnum::getDefaultRoles();

		self::assertArrayHasKey('User', $defaults);
		self::assertSame('ROLE_USER', (string) $defaults['User']->getValue());
	}

	public function testConvertEnumsToStrings(): void
	{
		$roles = ['Member' => UserRoleEnum::tryFrom('ROLE_MEMBER')];

		// Note: UserRoleManager::convertEnumsToStrings is tested via PermissionChecker
		// Here we test the enum values directly
		$enum = UserRoleEnum::tryFrom('ROLE_MEMBER');
		self::assertNotNull($enum);
		self::assertSame('ROLE_MEMBER', (string) $enum->getValue());
	}
}
