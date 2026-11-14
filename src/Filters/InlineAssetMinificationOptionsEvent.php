<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class InlineAssetMinificationOptionsEvent extends Event
{
	public const NAME = 'iikiti.inline_asset_minification.resolve';

	public function __construct(
		private readonly Request $request,
		private readonly ?Site $site,
		private bool $cssMinify,
		private bool $jsMinify,
		private bool $jsUglify,
	) {
	}

	public function getRequest(): Request
	{
		return $this->request;
	}

	public function getSite(): ?Site
	{
		return $this->site;
	}

	public function shouldMinifyCss(): bool
	{
		return $this->cssMinify;
	}

	public function setMinifyCss(bool $enabled): void
	{
		$this->cssMinify = $enabled;
	}

	public function shouldMinifyJavaScript(): bool
	{
		return $this->jsMinify;
	}

	public function setMinifyJavaScript(bool $enabled): void
	{
		$this->jsMinify = $enabled;
	}

	public function shouldUglifyJavaScript(): bool
	{
		return $this->jsUglify;
	}

	public function setUglifyJavaScript(bool $enabled): void
	{
		$this->jsUglify = $enabled;
	}

	public function options(): InlineAssetMinificationOptions
	{
		return new InlineAssetMinificationOptions(
			$this->cssMinify,
			$this->jsMinify,
			$this->jsMinify && $this->jsUglify,
		);
	}
}
