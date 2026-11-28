<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds default FFmpeg video (`video/*`) and audio (`audio/*`) asset processing
 * workflow definitions owned by the `iikiti-media-av` plugin.
 */
final class Version20261128025000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Seed default ffmpeg-video and ffmpeg-audio digital asset workflow definitions for iikiti-media-av.';
	}

	public function up(Schema $schema): void
	{
		$definitions = $this->table('workflow_definitions');
		$versions = $this->table('workflow_definition_versions');

		$this->seedWorkflow(
			$definitions,
			$versions,
			'ffmpeg-video',
			'FFmpeg Video Processing',
			'ffmpeg_video',
			'video/*',
			[
				'poster' => true,
				'poster_offset_percent' => 10,
				'waveform' => true,
				'waveform_buckets' => 120,
				'hls' => false,
			],
		);

		$this->seedWorkflow(
			$definitions,
			$versions,
			'ffmpeg-audio',
			'FFmpeg Audio Processing',
			'ffmpeg_audio',
			'audio/*',
			[
				'waveform' => true,
				'waveform_buckets' => 120,
				'hls' => false,
			],
		);
	}

	public function down(Schema $schema): void
	{
		$definitions = $this->table('workflow_definitions');
		$versions = $this->table('workflow_definition_versions');
		$instances = $this->table('workflow_instances');
		$this->addSql(
			sprintf(
				"DELETE FROM %s wd WHERE wd.owner_type = 'plugin' AND wd.owner_key = 'iikiti-media-av' AND wd.workflow_key IN ('ffmpeg-video', 'ffmpeg-audio') AND NOT EXISTS (SELECT 1 FROM %s wi INNER JOIN %s wv ON wv.id = wi.definition_version_id WHERE wv.definition_id = wd.id)",
				$definitions,
				$instances,
				$versions,
			),
		);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function seedWorkflow(
		string $definitionsTable,
		string $versionsTable,
		string $workflowKey,
		string $name,
		string $processorKey,
		string $mimePattern,
		array $options,
	): void {
		$graph = [
			'type' => 'workflow',
			'places' => ['pending', 'running', 'complete', 'failed', 'skipped'],
			'transitions' => [
				[
					'name' => 'start',
					'from' => ['pending', 'running', 'failed', 'skipped'],
					'to' => ['running'],
					'metadata' => [
						'navigation' => 'forward',
						'assetOperation' => 'start',
						'incrementAttempts' => true,
						'clearLastError' => true,
					],
				],
				[
					'name' => 'complete',
					'from' => ['running'],
					'to' => ['complete'],
					'metadata' => [
						'assetOperation' => 'complete',
						'clearLastError' => true,
					],
				],
				[
					'name' => 'fail',
					'from' => ['running'],
					'to' => ['failed'],
					'metadata' => [
						'assetOperation' => 'fail',
						'setLastError' => true,
					],
				],
				[
					'name' => 'skip',
					'from' => ['running'],
					'to' => ['skipped'],
					'metadata' => [
						'assetOperation' => 'skip',
						'clearLastError' => true,
					],
				],
			],
			'initialPlaces' => ['pending'],
			'finalPlaces' => ['complete'],
			'metadata' => [
				'policyKey' => 'core.asset_processor',
				'assetProcessor' => [
					'processorKey' => $processorKey,
					'mimePattern' => $mimePattern,
					'conditions' => (object) [],
					'options' => $options,
					'allSites' => true,
				],
			],
		];

		$graphJson = json_encode($graph, JSON_THROW_ON_ERROR);
		$sourceHash = hash('sha256', $graphJson);

		$this->addSql(
			sprintf(
				"INSERT INTO %s (workflow_key, name, owner_type, owner_key, current_version, is_enabled, is_archived, lock_version, created_at, updated_at) VALUES (:workflow_key, :name, 'plugin', 'iikiti-media-av', 1, true, false, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (owner_type, owner_key, workflow_key) DO NOTHING",
				$definitionsTable,
			),
			[
				'workflow_key' => $workflowKey,
				'name' => $name,
			],
		);

		$this->addSql(
			sprintf(
				"INSERT INTO %s (definition_id, version, graph, policy_key, source_hash, created_by_user_id, created_at) SELECT d.id, 1, CAST(:graph AS JSON), 'core.asset_processor', :source_hash, NULL, CURRENT_TIMESTAMP FROM %s d WHERE d.owner_type = 'plugin' AND d.owner_key = 'iikiti-media-av' AND d.workflow_key = :workflow_key ON CONFLICT (definition_id, version) DO NOTHING",
				$versionsTable,
				$definitionsTable,
			),
			[
				'graph' => $graphJson,
				'source_hash' => $sourceHash,
				'workflow_key' => $workflowKey,
			],
		);
	}

	private function table(string $name): string
	{
		$quotedName = $this->connection->quoteSingleIdentifier($name);
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schema) || '' === trim($schema)) {
			return $quotedName;
		}
		$trimmedSchema = trim($schema);
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmedSchema)) {
			throw new \InvalidArgumentException(sprintf('Invalid DB_SCHEMA identifier "%s".', $trimmedSchema));
		}

		return $this->connection->quoteSingleIdentifier($trimmedSchema).'.'.$quotedName;
	}
}
