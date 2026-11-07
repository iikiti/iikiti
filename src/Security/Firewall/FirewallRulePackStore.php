<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FirewallRulePackStore
{
	public function __construct(
		#[Autowire('%iikiti_security_firewall_pack_directory%')]
		private string $directory,
		private FirewallRulePackSourceRegistry $sources,
		private FirewallRuleValidator $validator,
	) {
	}

	/** @param list<array<string,mixed>> $rules */
	public function activate(string $source, string $version, string $checksum, array $rules): void
	{
		$this->assertIdentifiers($source, $version);
		if (!preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
			throw new \InvalidArgumentException('Firewall rule-pack checksum is invalid.');
		}
		$rules = $this->validator->validateRules($rules);
		$directory = $this->sourceDirectory($source);
		$this->ensureDirectory($directory);
		$lock = fopen($directory.'/.lock', 'c');
		if (false === $lock || !flock($lock, LOCK_EX)) {
			throw new \RuntimeException('Unable to lock firewall rule-pack storage.');
		}
		try {
			$artifactPath = $directory.'/'.$version.'.json';
			$artifact = json_encode([
				'source' => $source,
				'version' => $version,
				'checksum' => $checksum,
				'activatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
				'rules' => $rules,
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			if (is_file($artifactPath)) {
				$existing = file_get_contents($artifactPath);
				$existingData = false === $existing ? null : json_decode($existing, true);
				if (!is_array($existingData) || ($existingData['source'] ?? null) !== $source ||
					($existingData['version'] ?? null) !== $version || ($existingData['checksum'] ?? null) !== $checksum ||
					($existingData['rules'] ?? null) !== $rules
				) {
					throw new \RuntimeException('An immutable firewall pack version already exists with different data.');
				}
			} else {
				$this->writeAtomically($artifactPath, $artifact);
			}
			$this->writeActivePointer($directory, $version, $checksum);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	public function activateVersion(string $source, string $version): void
	{
		$this->assertIdentifiers($source, $version);
		$directory = $this->sourceDirectory($source);
		$artifact = $this->readJson($directory.'/'.$version.'.json');
		$checksum = $artifact['checksum'] ?? null;
		$rules = $artifact['rules'] ?? null;
		if (($artifact['source'] ?? null) !== $source || ($artifact['version'] ?? null) !== $version ||
			!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D', $checksum) ||
			!is_array($rules) || !array_is_list($rules)
		) {
			throw new \InvalidArgumentException('Stored firewall rule-pack version is invalid.');
		}
		$validatedRules = $this->validator->validateRules($rules);
		if ($validatedRules !== $rules) {
			throw new \InvalidArgumentException('Stored firewall rule-pack version is not canonical.');
		}
		$this->ensureDirectory($directory);
		$lock = fopen($directory.'/.lock', 'c');
		if (false === $lock || !flock($lock, LOCK_EX)) {
			throw new \RuntimeException('Unable to lock firewall rule-pack storage.');
		}
		try {
			$this->writeActivePointer($directory, $version, $checksum);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** @return list<array<string,mixed>> */
	public function getActiveRules(): array
	{
		$rules = [];
		foreach ($this->sources->getKeys() as $source) {
			$activePath = $this->sourceDirectory($source).'/active.json';
			if (!is_file($activePath)) {
				continue;
			}
			$pointer = $this->readJson($activePath);
			$version = $pointer['version'] ?? null;
			$checksum = $pointer['checksum'] ?? null;
			if (!is_string($version) || !is_string($checksum)) {
				throw new \RuntimeException(sprintf('Active firewall pack pointer for "%s" is malformed.', $source));
			}
			$this->assertIdentifiers($source, $version);
			$artifact = $this->readJson($this->sourceDirectory($source).'/'.$version.'.json');
			if (($artifact['source'] ?? null) !== $source || ($artifact['version'] ?? null) !== $version ||
				($artifact['checksum'] ?? null) !== $checksum || !is_array($artifact['rules'] ?? null) || !array_is_list($artifact['rules'])
			) {
				throw new \RuntimeException(sprintf('Active firewall pack artifact for "%s" is inconsistent.', $source));
			}
			foreach ($artifact['rules'] as $rule) {
				if (!is_array($rule)) {
					throw new \RuntimeException(sprintf('Active firewall pack "%s" contains invalid rules.', $source));
				}
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	public function getActiveVersion(string $source): ?string
	{
		$pointerPath = $this->sourceDirectory($source).'/active.json';
		if (!is_file($pointerPath)) {
			return null;
		}
		$pointer = $this->readJson($pointerPath);
		$version = $pointer['version'] ?? null;
		$checksum = $pointer['checksum'] ?? null;
		if (!is_string($version) || !FirewallRulePackVersion::isValid($version) || !is_string($checksum) ||
			!preg_match('/^[a-f0-9]{64}$/D', $checksum)
		) {
			throw new \RuntimeException('Active firewall rule-pack pointer is malformed.');
		}

		return $version;
	}

	/** @return list<array{key:string,name:string,activeVersion:?string,checksum:?string,versions:list<string>}> */
	public function getStatuses(): array
	{
		$statuses = [];
		foreach ($this->sources->all() as $source) {
			$directory = $this->sourceDirectory($source->getKey());
			$activeVersion = null;
			$checksum = null;
			$pointerPath = $directory.'/active.json';
			if (is_file($pointerPath)) {
				$pointer = $this->readJson($pointerPath);
				$activeVersion = is_string($pointer['version'] ?? null) ? $pointer['version'] : null;
				$checksum = is_string($pointer['checksum'] ?? null) ? $pointer['checksum'] : null;
			}
			$versions = [];
			foreach (glob($directory.'/*.json') ?: [] as $path) {
				$version = basename($path, '.json');
				if ('active' !== $version && FirewallRulePackVersion::isValid($version)) {
					$versions[] = $version;
				}
			}
			sort($versions);
			$statuses[] = ['key' => $source->getKey(), 'name' => $source->getName(), 'activeVersion' => $activeVersion, 'checksum' => $checksum, 'versions' => $versions];
		}

		return $statuses;
	}

	private function sourceDirectory(string $source): string
	{
		if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $source)) {
			throw new \InvalidArgumentException('Firewall rule-pack source key is invalid.');
		}

		return rtrim($this->directory, '/').'/'.$source;
	}

	private function assertIdentifiers(string $source, string $version): void
	{
		$this->sources->get($source);
		if (!FirewallRulePackVersion::isValid($version)) {
			throw new \InvalidArgumentException('Firewall rule-pack version is invalid.');
		}
	}

	private function ensureDirectory(string $directory): void
	{
		if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
			throw new \RuntimeException('Unable to create firewall rule-pack storage.');
		}
	}

	private function writeAtomically(string $path, string $contents): void
	{
		$directory = dirname($path);
		$temp = tempnam($directory, '.stage-');
		if (false === $temp) {
			throw new \RuntimeException('Unable to stage firewall rule-pack data.');
		}
		try {
			if (false === file_put_contents($temp, $contents, LOCK_EX) || !@rename($temp, $path)) {
				throw new \RuntimeException('Unable to activate firewall rule-pack data.');
			}
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	private function writeActivePointer(string $directory, string $version, string $checksum): void
	{
		$this->writeAtomically($directory.'/active.json', json_encode([
			'version' => $version,
			'checksum' => $checksum,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
	}

	/** @return array<string,mixed> */
	private function readJson(string $path): array
	{
		$contents = @file_get_contents($path);
		if (false === $contents) {
			throw new \RuntimeException('Firewall rule-pack data is unavailable.');
		}
		$data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \RuntimeException('Firewall rule-pack data is malformed.');
		}

		return $data;
	}
}
