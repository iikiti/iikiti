<?php

namespace iikiti\CMS\Loader;

use iikiti\CMS\Kernel;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('extensions')]
class Extensions
{
	public const PREFIX = 'iikiti\\extension\\';
	public const INITIAL_KEY = 'initial';

	public static array $EXTENSIONS = [];

	public function __construct(private SiteRegistry $siteRegistry)
	{
	}

	/**
	 * Acquires list of enabled extensions and loads them via PSR-4 then
	 * creates a new instance.
	 */
	public function load(Kernel $kernel): \Generator
	{
		$gen = $this->_loadFromDirectory($kernel);
		$cur = $gen->current();
		if (null !== $cur) {
			self::$EXTENSIONS[
				$this->siteRegistry::getCurrent()->getId() ?? self::INITIAL_KEY
			][] = $gen->current();
		}
		yield from null !== $cur ? $gen : [];
	}

	public function setInitialSiteId(int|string $siteId): void
	{
		self::$EXTENSIONS[$siteId] = self::$EXTENSIONS[self::INITIAL_KEY] ?? [];
		unset(self::$EXTENSIONS[self::INITIAL_KEY]);
	}

	protected function _loadFromDirectory(Kernel $kernel): \Generator
	{
		$PATH = $kernel->getProjectDir().'/cms/extensions/active/';
		$fileLoop = new \CallbackFilterIterator(
			new \DirectoryIterator($PATH),
			[__CLASS__, '_directoryFilter']
		);
		foreach ($fileLoop as $entry) {
			/** @var \DirectoryIterator $entry */
			$bundleNS = (string) json_decode(file_get_contents(
				$entry->getPath().'/'.$entry->getFilename().
				'/composer.json'
			))->name;
			$bundleName = ucwords(
				implode('', array_slice(explode('/', $bundleNS), -1))
			);
			/** @psalm-suppress UnresolvableInclude */
			require_once $entry->getPath().'/'.$entry->getFilename().'/'.
				$bundleName.'Bundle.php';
			$bundleClass = str_replace('/', '\\', $bundleNS).'\\'.
				$bundleName.'Bundle';
			/*
			 TODO Find a better way to get extension list
				  (or any required arguments) to the extension bundles.
			 */
			yield new $bundleClass($this->getExtensions());
		}
	}

	protected static function _directoryFilter(
		\DirectoryIterator $current,
		int $key,
		\Iterator $iterator
	): bool {
		return false === $current->isDot() && $current->isDir() && $current->isLink();
	}

	public function getExtensions(): array
	{
		return self::$EXTENSIONS[
			$this->siteRegistry::getCurrent()->getId() ?? self::INITIAL_KEY
		] ?? [];
	}
}
