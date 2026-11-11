<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\ObjectPropertyRepository;

/**
 * Database property entity
 * Allows access to object metadata.
 *
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: 'object_properties')]
class ObjectProperty
{
	#[ORM\Id()]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'properties')]
	#[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id')]
	private ?DbObject $object = null;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string $object_id = 0;

	#[ORM\Column(type: Types::STRING)]
	private string $name;

	/**
	 * @var int|float|string|array<int|string,mixed>|null
	 */
	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array|null $value;

	#[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
	private bool $secret = false;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $created;

	#[ORM\OneToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: true)]
	private ?User $creator = null;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $creator_id = null;

	public function __construct()
	{
		$this->created = new \DateTimeImmutable();
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): void
	{
		$this->name = $name;
	}

	public function getValue(): mixed
	{
		return $this->secret ? null : $this->value;
	}

	public function setValue(mixed $value): void
	{
		if ($this->secret) {
			throw new \LogicException('Secret properties can only be changed through SecretsApi.');
		}

		$this->value = $value;
	}

	public function isSecret(): bool
	{
		return $this->secret;
	}

	public function readEncryptedSecretPayload(): mixed
	{
		return $this->secret ? $this->value : null;
	}

	/** @param array{version:int,nonce:string,ciphertext:string} $payload */
	public function writeEncryptedSecretPayload(array $payload): void
	{
		$this->value = $payload;
		$this->secret = true;
	}

	public function getCreator(): ?User
	{
		return $this->creator;
	}

	public function getCreationDate(): ?\DateTimeInterface
	{
		return $this->created;
	}

	public function setCreationDate(\DateTimeImmutable $created): void
	{
		$this->created = $created;
	}

	public function setObject(DbObject $object): void
	{
		$this->object = $object;
	}

	public function getId(): int|float
	{
		return $this->id;
	}

	public function getObject(): ?DbObject
	{
		return $this->object;
	}
}
