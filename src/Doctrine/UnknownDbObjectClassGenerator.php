<?php

declare(strict_types=1);

namespace iikiti\CMS\Doctrine;

use iikiti\CMS\Entity\UnknownDbObject;
use iikiti\CMS\Plugin\PluginAutoloader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates and autoloads 1-to-1 read-only {@see UnknownDbObject} entity
 * proxy classes in the cache directory for unloaded plugin STI discriminators.
 */
final class UnknownDbObjectClassGenerator
{
	public const NAMESPACE_PREFIX = 'iikiti\\CMS\\Generated\\UnknownDbObject';
	public const CACHE_SUBDIR = 'iikiti_unknown_objects';

	public function __construct(
		#[Autowire('%kernel.cache_dir%/'.self::CACHE_SUBDIR)]
		private readonly string $directory,
	) {
	}

	public function getDirectory(): string
	{
		return $this->directory;
	}

	public function ensureDirectory(): void
	{
		if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
			throw new \RuntimeException(sprintf('Unable to create unknown object proxy directory "%s".', $this->directory));
		}
	}

	/**
	 * Ensure a concrete read-only proxy class exists for the given discriminator
	 * and return its FQCN.
	 *
	 * @return class-string<UnknownDbObject>
	 */
	public function ensureClassForDiscriminator(string $discriminator): string
	{
		if ('' === $discriminator || strlen($discriminator) > 128 || 1 === preg_match('/[\x00-\x1f\x7f]/', $discriminator)) {
			throw new \InvalidArgumentException('Invalid DbObject discriminator value.');
		}

		$shortName = 'Unknown_'.substr(hash('sha256', $discriminator), 0, 16);
		/** @var class-string<UnknownDbObject> $fqcn */
		$fqcn = self::NAMESPACE_PREFIX.'\\'.$shortName;

		if (class_exists($fqcn)) {
			return $fqcn;
		}

		$this->ensureDirectory();
		PluginAutoloader::register(self::NAMESPACE_PREFIX, $this->directory);

		$file = $this->directory.'/'.$shortName.'.php';
		if (!is_file($file)) {
			$exported = var_export($discriminator, true);
			$code = <<<PHP
<?php

declare(strict_types=1);

namespace iikiti\CMS\Generated\UnknownDbObject;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\UnknownDbObject;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'object_lookup')]
final class {$shortName} extends UnknownDbObject
{
	public static function getUnknownDiscriminator(): string
	{
		return {$exported};
	}
}

PHP;
			$tmpFile = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
			if (false !== @file_put_contents($tmpFile, $code)) {
				@rename($tmpFile, $file);
			} else {
				@unlink($tmpFile);
			}
		}

		if (is_file($file)) {
			require_once $file;
		}

		if (!class_exists($fqcn)) {
			throw new \RuntimeException(sprintf('Failed to generate UnknownDbObject proxy class "%s".', $fqcn));
		}

		return $fqcn;
	}
}
