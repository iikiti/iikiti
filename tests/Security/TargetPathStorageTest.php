<?php

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Security\TargetPathStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class TargetPathStorageTest extends TestCase
{
	public function testStoresAndPullsInternalPath(): void
	{
		$storage = $this->createStorage();
		$storage->store('/admin?tab=users');

		$this->assertSame('/admin?tab=users', $storage->pull());
		$this->assertNull($storage->pull());
	}

	#[DataProvider('unsafePaths')]
	public function testRejectsUnsafePaths(string $path): void
	{
		$storage = $this->createStorage();
		$storage->store($path);

		$this->assertNull($storage->pull());
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function unsafePaths(): array
	{
		return [
			'protocol-relative' => ['//evil.example.com'],
			'backslash' => ['/\evil.example.com'],
			'absolute' => ['https://evil.example.com'],
			'relative' => ['admin'],
			'empty' => [''],
		];
	}

	private function createStorage(): TargetPathStorage
	{
		$session = new Session(new MockArraySessionStorage());
		$requestStack = $this->createStub(RequestStack::class);
		$requestStack->method('getSession')->willReturn($session);

		return new TargetPathStorage($requestStack);
	}
}
