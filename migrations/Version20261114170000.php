<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use iikiti\CMS\Service\ResponseHeaderPolicy;

final class Version20261114170000 extends AbstractMigration
{
	private const DEFAULT_HEADERS = [
		'Content-Security-Policy',
		'Strict-Transport-Security',
		'X-Frame-Options',
		'X-Content-Type-Options',
		'Referrer-Policy',
		'Permissions-Policy',
		'Cross-Origin-Opener-Policy',
		'Cross-Origin-Embedder-Policy',
		'Cross-Origin-Resource-Policy',
	];

	public function getDescription(): string
	{
		return 'Copy legacy site security header policies into response header policies.';
	}

	public function up(Schema $schema): void
	{
		$schemaName = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schemaName) || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schemaName)) {
			throw new \RuntimeException('DB_SCHEMA must be configured as a simple PostgreSQL schema identifier.');
		}
		$prefix = $this->connection->quoteSingleIdentifier($schemaName).'.';
		$properties = $prefix.$this->connection->quoteSingleIdentifier('object_properties');
		$objects = $prefix.$this->connection->quoteSingleIdentifier('objects');
		$rows = $this->connection->iterateAssociative(sprintf(
			"SELECT legacy.object_id, legacy.value FROM %s legacy JOIN %s object ON object.id = legacy.object_id AND object.type = 'site' WHERE legacy.name = 'security_headers_policy' AND NOT EXISTS (SELECT 1 FROM %s target WHERE target.object_id = legacy.object_id AND target.name = 'response_headers_policy')",
			$properties, $objects, $properties,
		));
		foreach ($rows as $row) {
			$policy = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($policy) || !isset($policy['version'], $policy['headers']) || !is_array($policy['headers'])) {
				throw new \UnexpectedValueException('Malformed legacy security header policy for site '.$row['object_id'].'.');
			}
			$rules = [];
			if ([] !== $policy['headers']) {
				$known = [...self::DEFAULT_HEADERS, 'Content-Security-Policy-Report-Only'];
				foreach ($policy['headers'] as $header) {
					if (!is_array($header) || !isset($header['name'], $header['mode'], $header['value'])) {
						throw new \UnexpectedValueException('Malformed legacy security header entry for site '.$row['object_id'].'.');
					}
					$name = (string) $header['name'];
					if (!in_array($name, $known, true)) {
						continue;
					}
					$value = (string) $header['value'];
					if ('off' !== (string) $header['mode'] && '' !== $value) {
						$rules[$name] = ['name' => $name, 'action' => 'set', 'value' => $value, 'priority' => 0];
					} elseif (!isset($rules[$name])) {
						$rules[$name] = ['name' => $name, 'action' => 'remove', 'priority' => 0];
					}
				}
				foreach (self::DEFAULT_HEADERS as $name) {
					$rules[$name] ??= ['name' => $name, 'action' => 'remove', 'priority' => 0];
				}
			}
			try {
				(new ResponseHeaderPolicy())->validateRules(array_values($rules), false);
				if (!is_numeric($policy['version']) || (int) $policy['version'] < 1) {
					throw new \InvalidArgumentException('Invalid policy version.');
				}
			} catch (\InvalidArgumentException $exception) {
				throw new \UnexpectedValueException('Cannot migrate invalid security header policy for site '.$row['object_id'].': '.$exception->getMessage(), 0, $exception);
			}
			$this->addSql(sprintf(
				"INSERT INTO %s (object_id, name, value, secret, created, creator_id) SELECT :object_id, 'response_headers_policy', CAST(:value AS JSON), FALSE, CURRENT_TIMESTAMP, NULL WHERE NOT EXISTS (SELECT 1 FROM %s WHERE object_id = :object_id AND name = 'response_headers_policy')",
				$properties, $properties,
			), [
				'object_id' => $row['object_id'],
				'value' => json_encode(['version' => (int) $policy['version'], 'rules' => array_values($rules)], JSON_THROW_ON_ERROR),
			]);
		}
	}

	public function down(Schema $schema): void
	{
		$this->throwIrreversibleMigrationException('Existing response header policies cannot be distinguished from migrated policies.');
	}
}
