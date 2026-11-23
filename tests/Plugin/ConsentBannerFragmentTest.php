<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\Extension\ConsentManagement\Fragment\ConsentBannerFragment;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require_once dirname(__DIR__, 2).'/plugins/consent-management/src/Fragment/ConsentBannerFragment.php';

final class ConsentBannerFragmentTest extends TestCase
{
	public function testBannerFragmentIsBodyCompatibleAndHasNoInlineHidingStyle(): void
	{
		$loader = new FilesystemLoader();
		$loader->addPath(dirname(__DIR__, 2).'/plugins/consent-management/templates', 'ConsentManagement');
		$twig = new Environment($loader);
		$twig->addFunction(new TwigFunction('encore_entry_script_tags', static fn (string $entry): string => sprintf('<script src="/build/%s.js" defer></script>', $entry)));
		$fragment = new ConsentBannerFragment($twig);
		$result = $fragment->render(new FragmentContext(new Site(), 'en'));

		self::assertStringContainsString('<div id="iikiti-consent-banner" hidden></div>', $result->html);
		self::assertStringContainsString('/build/consent-manager.js', $result->html);
		self::assertStringContainsString('/build/consent-banner.js', $result->html);
		self::assertStringNotContainsString('style=', $result->html);
		self::assertSame([], $result->styles);
	}
}
