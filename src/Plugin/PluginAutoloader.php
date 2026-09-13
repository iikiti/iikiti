<?php

namespace iikiti\CMS\Plugin;

/**
 * Minimal PSR-4 autoloader for plugin namespaces.
 *
 * Plugins live outside `vendor/` and are not known to Composer, so the kernel
 * registers each active plugin's namespace here during boot. The autoloader is
 * independent of Composer internals so it behaves identically in web, CLI and
 * test contexts.
 */
final class PluginAutoloader
{
	/** @var array<string,string> namespace prefix => base directory (no trailing slash) */
	private static array $prefixes = [];

	/** @var list<string> prefixes sorted longest-first for O(1) lookup */
	private static array $sortedPrefixes = [];

	private static bool $registered = false;

	private function __construct()
	{
	}

	/**
	 * Register a namespace prefix mapped to a source directory.
	 */
	public static function register(string $namespace, string $directory): void
	{
		$prefix = trim($namespace, '\\').'\\';
		$baseDir = rtrim($directory, '/\\');

		self::$prefixes[$prefix] = $baseDir;
		self::rebuildSortedPrefixes();
		self::ensureRegistered();
	}

	/**
	 * Register a plugin's namespace, mapping it to its `src/` directory when
	 * present (the conventional PSR-4 layout), otherwise to the plugin root.
	 */
	public static function registerPlugin(PluginManifest $manifest, string $pluginPath): void
	{
		$pluginPath = rtrim($pluginPath, '/\\');
		$sourceDir = is_dir($pluginPath.'/src') ? $pluginPath.'/src' : $pluginPath;

		self::register($manifest->namespace, $sourceDir);
	}

	/**
	 * Remove a previously registered namespace prefix.
	 */
	public static function unregister(string $namespace): void
	{
		unset(self::$prefixes[trim($namespace, '\\').'\\']);
		self::rebuildSortedPrefixes();
	}

	/**
	 * @return array<string,string>
	 */
	public static function getPrefixes(): array
	{
		return self::$prefixes;
	}

	public static function reset(): void
	{
		self::$prefixes = [];
		self::$sortedPrefixes = [];
	}

	/**
	 * Resolve a class name to a file using longest-prefix-first matching.
	 */
	public static function load(string $class): bool
	{
		if ('' === $class) {
			return false;
		}

		foreach (self::$sortedPrefixes as $prefix) {
			if (!str_starts_with($class, $prefix)) {
				continue;
			}

			$relative = substr($class, strlen($prefix));
			$file = self::$prefixes[$prefix].'/'.str_replace('\\', '/', $relative).'.php';
			if (is_file($file)) {
				require_once $file;

				return true;
			}
		}

		return false;
	}

	private static function rebuildSortedPrefixes(): void
	{
		$prefixes = array_keys(self::$prefixes);
		usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
		self::$sortedPrefixes = $prefixes;
	}

	private static function ensureRegistered(): void
	{
		if (self::$registered) {
			return;
		}

		spl_autoload_register(static function (string $class): void {
			self::load($class);
		});
		self::$registered = true;
	}
}
