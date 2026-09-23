<?php

namespace iikiti\CMS\Plugin;

use Symfony\Component\DependencyInjection\Kernel\BundleInterface;

/**
 * Contract every iikiti plugin bundle must satisfy.
 */
interface PluginBundleInterface extends BundleInterface
{
	/**
	 * The unique, lowercase plugin slug (e.g. "myblog").
	 */
	public function getPluginSlug(): string;

	/**
	 * Human-readable plugin name.
	 */
	public function getPluginName(): string;

	/**
	 * Installed plugin version.
	 */
	public function getPluginVersion(): string;

	/**
	 * The manifest describing this plugin, if it has been attached.
	 */
	public function getPluginManifest(): ?PluginManifest;
}
