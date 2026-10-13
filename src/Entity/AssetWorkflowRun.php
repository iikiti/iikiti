<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'digital_asset_workflow_runs')]
#[ORM\UniqueConstraint(name: 'uniq_digital_asset_workflow_run', columns: ['asset_id', 'workflow_id'])]
class AssetWorkflowRun
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: AbstractDigitalAsset::class)]
	#[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private AbstractDigitalAsset $asset;

	#[ORM\ManyToOne(targetEntity: AssetWorkflowDefinition::class)]
	#[ORM\JoinColumn(name: 'workflow_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private AssetWorkflowDefinition $workflow;

	#[ORM\Column(type: Types::STRING, length: 16)]
	private string $status = 'pending';

	#[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
	private int $attempts = 0;

	#[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
	private ?string $lastError = null;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	public function __construct(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow)
	{
		$this->asset = $asset;
		$this->workflow = $workflow;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getAsset(): AbstractDigitalAsset
	{
		return $this->asset;
	}

	public function getWorkflow(): AssetWorkflowDefinition
	{
		return $this->workflow;
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function getAttempts(): int
	{
		return $this->attempts;
	}

	public function getLastError(): ?string
	{
		return $this->lastError;
	}

	public function start(): void
	{
		$this->status = 'running';
		++$this->attempts;
		$this->lastError = null;
		$this->touch();
	}

	public function complete(): void
	{
		$this->status = 'complete';
		$this->lastError = null;
		$this->touch();
	}

	public function fail(string $error): void
	{
		$this->status = 'failed';
		$this->lastError = mb_substr($error, 0, 2000);
		$this->touch();
	}

	public function skip(): void
	{
		$this->status = 'skipped';
		$this->lastError = null;
		$this->touch();
	}

	private function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
