<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Entity\SecretSiteKey;
use iikiti\CMS\Secrets\SecretsApi;
use iikiti\CMS\Secrets\SecretsKeyring;
use iikiti\CMS\Secrets\SiteMasterKeyManager;
use PHPUnit\Framework\TestCase;

final class SecretsApiTest extends TestCase
{
	public function testJsonValuesAreEncryptedScopedAndUpdatedWithoutFlushing(): void
	{
		[$api, $object, $keys] = $this->createApi($this->object(45, 12));
		$value = ['token' => 'never-store-this-in-clear', 'options' => [true, 7, null]];

		$api->set($object, 'acme.integration', $value);

		$property = $object->getProperties()->get('acme.integration');
		self::assertInstanceOf(ObjectProperty::class, $property);
		self::assertTrue($property->isSecret());
		self::assertNull($property->getValue());
		self::assertStringNotContainsString('never-store-this-in-clear', json_encode($property->readEncryptedSecretPayload(), JSON_THROW_ON_ERROR));
		self::assertSame(1, $keys->count());
		self::assertSame($value, $api->get($object, 'acme.integration'));

		$api->set($object, 'acme.integration', ['updated' => 1]);
		self::assertSame(['updated' => 1], $api->get($object, 'acme.integration'));
	}

	public function testNullIsDistinguishableFromMissingAndDeleteRemovesOnlySecrets(): void
	{
		[$api, $object] = $this->createApi($this->object(45, 0));
		$object->setProperty('ordinary', 'keep');

		self::assertNull($api->get($object, 'acme.null'));
		self::assertFalse($api->has($object, 'acme.null'));
		$api->set($object, 'acme.null', null);
		self::assertTrue($api->has($object, 'acme.null'));
		self::assertNull($api->get($object, 'acme.null'));

		$api->delete($object, 'ordinary');
		self::assertSame('keep', $object->getProperties()->get('ordinary')->getValue());
		$api->delete($object, 'acme.null');
		self::assertFalse($api->has($object, 'acme.null'));
	}

	public function testPayloadAuthenticationAndOwnerBindingFailClosed(): void
	{
		[$api, $object] = $this->createApi($this->object(45, 0));
		$api->set($object, 'acme.key', 'secret-value');
		$property = $object->getProperties()->get('acme.key');
		$payload = $property->readEncryptedSecretPayload();
		$payload['ciphertext'] = base64_encode('tampered ciphertext');
		$property->writeEncryptedSecretPayload($payload);

		try {
			$api->get($object, 'acme.key');
			self::fail('Tampered payload must not be decrypted.');
		} catch (\UnexpectedValueException $exception) {
			self::assertStringNotContainsString('secret-value', $exception->getMessage());
		}

		[$api, $object] = $this->createApi($this->object(45, 0));
		$api->set($object, 'acme.key', 'owner-bound');
		$otherOwner = $this->object(46, 0);
		$otherOwner->getProperties()->set('acme.key', $object->getProperties()->get('acme.key'));
		try {
			$api->get($otherOwner, 'acme.key');
			self::fail('A payload copied to another object must not authenticate.');
		} catch (\UnexpectedValueException $exception) {
			self::assertStringContainsString('authenticated', $exception->getMessage());
		}
	}

	public function testInvalidValueDoesNotCreateMasterKeyAndGenericWritesCannotReplaceSecrets(): void
	{
		[$api, $object, $keys] = $this->createApi($this->object(45, 0));
		$resource = fopen('php://memory', 'r');
		try {
			$api->set($object, 'acme.invalid', $resource);
			self::fail('Resources are not JSON encodable.');
		} catch (\InvalidArgumentException) {
		} finally {
			fclose($resource);
		}
		self::assertSame(0, $keys->count());

		$api->set($object, 'acme.key', 'secret');
		try {
			$object->setProperty('acme.key', 'plaintext');
			self::fail('Generic writes cannot replace secret values.');
		} catch (\LogicException) {
		}
		$this->expectException(\LogicException::class);
		$object->setProperties(new ArrayCollection());
	}

	public function testSiteAndGlobalScopesUseDistinctMasterKeysAndRequirePersistedSiteObjects(): void
	{
		$object = $this->object(45, 12);
		[$api, , $keys] = $this->createApi($object);
		$api->set($object, 'acme.key', 'site secret');
		$globalObject = $this->object(46, 0);
		$api->set($globalObject, 'acme.key', 'global secret');

		self::assertSame(2, $keys->count());
		self::assertNotSame($keys->masterKey('site:12'), $keys->masterKey('global'));

		$site = new Site();
		try {
			$api->set($site, 'acme.key', 'unpersisted site');
			self::fail('An unpersisted Site has no key scope.');
		} catch (\InvalidArgumentException $exception) {
			self::assertStringContainsString('persisted', $exception->getMessage());
		}

		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 90);
		$api->set($site, 'acme.key', 'site-owned secret');
		self::assertSame('site-owned secret', $api->get($site, 'acme.key'));
		self::assertSame(3, $keys->count());
		self::assertNotSame($keys->masterKey('site:90'), $keys->masterKey('global'));
	}

	/** @return array{SecretsApi,DbObject,SecretsApiTestKeyRegistry} */
	private function createApi(DbObject $object): array
	{
		$keyring = new SecretsKeyring(['test-v1' => base64_encode(str_repeat('k', 32))], 'test-v1');
		$keys = new SecretsApiTestKeyRegistry($keyring);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('find')->willReturnCallback(static function (string $class, mixed $id) use ($keys): ?SecretSiteKey {
			return SecretSiteKey::class === $class ? $keys->get((string) $id) : null;
		});
		$entityManager->method('persist')->willReturnCallback(static function (object $entity) use ($keys): void {
			if ($entity instanceof SecretSiteKey) {
				$keys->add($entity);
			}
		});
		$entityManager->method('getReference')->willReturnCallback(static fn (string $class, mixed $id): Site => new Site());
		$api = new SecretsApi(new SiteMasterKeyManager($entityManager, $keyring));

		return [$api, $object, $keys];
	}

	private function object(int $id, int $siteId): Page
	{
		$object = new Page();
		$object->setSiteId($siteId);
		$object->setCreatorId(1);
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($object, $id);

		return $object;
	}
}

final class SecretsApiTestKeyRegistry
{
	/** @var array<string,SecretSiteKey> */
	private array $keys = [];

	public function __construct(private readonly SecretsKeyring $keyring)
	{
	}

	public function add(SecretSiteKey $key): void
	{
		$this->keys[$key->getScopeKey()] = $key;
	}

	public function get(string $scopeKey): ?SecretSiteKey
	{
		return $this->keys[$scopeKey] ?? null;
	}

	public function count(): int
	{
		return count($this->keys);
	}

	public function masterKey(string $scopeKey): string
	{
		$key = $this->keys[$scopeKey] ?? throw new \LogicException('Expected test key was not created.');

		return $this->keyring->unwrap(
			$key->getWrappingKeyId(),
			$key->getWrappedMasterKey(),
			"iikiti-site-master-v1\0".$scopeKey,
		);
	}
}
