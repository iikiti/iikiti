<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity;

use iikiti\CMS\Entity\AuditLogEntry;
use PHPUnit\Framework\TestCase;

final class AuditLogEntryTest extends TestCase
{
	public function testConstructorSetsCreatedDate(): void
	{
		$entry = new AuditLogEntry();

		self::assertInstanceOf(\DateTimeImmutable::class, $entry->getCreatedAt());
	}

	public function testDefaultActorType(): void
	{
		$entry = new AuditLogEntry();

		self::assertSame('user', $entry->getActorType());
	}

	public function testSetAction(): void
	{
		$entry = new AuditLogEntry();
		$entry->setAction('created');

		self::assertSame('created', $entry->getAction());
	}

	public function testSetObjectType(): void
	{
		$entry = new AuditLogEntry();
		$entry->setObjectType('User');

		self::assertSame('User', $entry->getObjectType());
	}

	public function testSetObjectId(): void
	{
		$entry = new AuditLogEntry();
		$entry->setObjectId(42);

		self::assertSame(42, $entry->getObjectId());
	}

	public function testSetActorType(): void
	{
		$entry = new AuditLogEntry();
		$entry->setActorType('system');

		self::assertSame('system', $entry->getActorType());
	}

	public function testBeforeAfterState(): void
	{
		$state = ['name' => 'test'];
		$entry = new AuditLogEntry();
		$entry->setBeforeState($state);
		$entry->setAfterState($state);

		self::assertSame($state, $entry->getBeforeState());
		self::assertSame($state, $entry->getAfterState());
	}

	public function testContext(): void
	{
		$entry = new AuditLogEntry();
		$context = ['environment' => 'dev', 'debug' => true];
		$entry->setContext($context);

		self::assertSame($context, $entry->getContext());
	}

	public function testIpAddress(): void
	{
		$entry = new AuditLogEntry();
		$entry->setIpAddress('192.168.1.1');

		self::assertSame('192.168.1.1', $entry->getIpAddress());
	}
}
