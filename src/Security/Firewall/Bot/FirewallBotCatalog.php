<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

/**
 * Curated, immutable registry of known automated clients.
 *
 * Only entries with a documented verification method carry verification metadata; the
 * rest are classified by User-Agent and treated as unverified.
 */
final class FirewallBotCatalog
{
	/** @var array<string,FirewallBotDefinition>|null */
	private static ?array $definitions = null;

	/** @return array<string,FirewallBotDefinition> */
	public function all(): array
	{
		return self::definitions();
	}

	public function get(string $id): ?FirewallBotDefinition
	{
		return self::definitions()[$id] ?? null;
	}

	/**
	 * Returns the first catalog entry whose User-Agent pattern matches. Ordering is
	 * deterministic (declaration order), so more specific entries are listed first.
	 */
	public function matchUserAgent(string $userAgent): ?FirewallBotDefinition
	{
		if ('' === trim($userAgent)) {
			return null;
		}

		foreach (self::definitions() as $definition) {
			if ($definition->matchesUserAgent($userAgent)) {
				return $definition;
			}
		}

		return null;
	}

	/** @return array<string,FirewallBotDefinition> */
	private static function definitions(): array
	{
		return self::$definitions ??= self::build();
	}

	/** @return array<string,FirewallBotDefinition> */
	private static function build(): array
	{
		$entries = [
			// Search engines: verified by published CIDR feeds or FCrDNS.
			self::bot('googlebot', 'Googlebot', 'search_engine', ['Googlebot'], 'cidr_pack', 'bot-google', ['.googlebot.com', '.google.com'], 'Google Search crawler.'),
			self::bot('bingbot', 'Bingbot', 'search_engine', ['bingbot'], 'cidr_pack', 'bot-bing', ['.search.msn.com'], 'Microsoft Bing crawler.'),
			self::bot('applebot', 'Applebot', 'search_engine', ['Applebot'], 'cidr_pack', 'bot-apple', ['.applebot.apple.com'], 'Apple search crawler.'),
			self::bot('duckduckbot', 'DuckDuckBot', 'search_engine', ['DuckDuckBot'], 'cidr_pack', 'bot-duckduckgo', [], 'DuckDuckGo crawler.'),

			// AI training crawlers: blocked by default posture.
			self::bot('gptbot', 'GPTBot', 'ai_training', ['GPTBot'], 'cidr_pack', 'bot-openai', [], 'OpenAI training crawler.'),
			self::bot('ccbot', 'CCBot', 'ai_training', ['CCBot'], 'fcrdns', null, ['.commoncrawl.org'], 'Common Crawl crawler.'),
			self::bot('google-extended', 'Google-Extended', 'ai_training', ['Google-Extended'], 'cidr_pack', 'bot-google', ['.google.com'], 'Google AI training control token.'),
			self::bot('claudebot', 'ClaudeBot', 'ai_training', ['ClaudeBot'], 'cidr_pack', 'bot-anthropic', [], 'Anthropic training crawler.'),
			self::bot('bytespider', 'Bytespider', 'ai_training', ['Bytespider'], 'none', null, [], 'ByteDance training crawler; no published verification.'),

			// AI agents: user-triggered fetches.
			self::bot('oai-searchbot', 'OAI-SearchBot', 'ai_agent', ['OAI-SearchBot'], 'cidr_pack', 'bot-openai', [], 'OpenAI search indexer.'),
			self::bot('chatgpt-user', 'ChatGPT-User', 'ai_agent', ['ChatGPT-User'], 'cidr_pack', 'bot-openai', [], 'ChatGPT user-triggered fetch.'),
			self::bot('claude-user', 'Claude-User', 'ai_agent', ['Claude-User'], 'cidr_pack', 'bot-anthropic', [], 'Claude user-triggered fetch.'),
			self::bot('perplexity-user', 'Perplexity-User', 'ai_agent', ['Perplexity-User'], 'cidr_pack', 'bot-perplexity', [], 'Perplexity user-triggered fetch.'),

			// Monitoring.
			self::bot('uptimerobot', 'UptimeRobot', 'monitoring', ['UptimeRobot'], 'cidr_pack', 'bot-uptimerobot', [], 'UptimeRobot availability monitor.'),
			self::bot('betterstack', 'Better Stack', 'monitoring', ['BetterStack', 'Better Uptime'], 'none', null, [], 'Better Stack uptime monitor; no published verification.'),

			// Social link previews.
			self::bot('slackbot', 'Slackbot', 'social_preview', ['Slackbot'], 'none', null, [], 'Slack link unfurler.'),
			self::bot('discordbot', 'Discordbot', 'social_preview', ['Discordbot'], 'none', null, [], 'Discord link unfurler.'),
			self::bot('twitterbot', 'Twitterbot', 'social_preview', ['Twitterbot'], 'none', null, [], 'X/Twitter card fetcher.'),

			// Malicious scanners. curl and python-requests are deliberately excluded.
			self::scanner('sqlmap', 'sqlmap'),
			self::scanner('nikto', 'Nikto'),
			self::scanner('nuclei', 'Nuclei'),
			self::scanner('masscan', 'masscan'),
			self::scanner('dirbuster', 'DirBuster'),
			self::scanner('gobuster', 'gobuster'),
			self::scanner('zgrab', 'zgrab'),
			self::scanner('wpscan', 'WPScan'),
			self::scanner('acunetix', 'Acunetix'),
			self::scanner('nessus', 'Nessus'),
			self::scanner('havij', 'Havij'),
			self::scanner('skipfish', 'Skipfish'),
		];

		$byId = [];
		foreach ($entries as $entry) {
			$byId[$entry->id] = $entry;
		}

		return $byId;
	}

	/**
	 * @param list<string> $userAgentPatterns
	 * @param list<string> $fcrdnsDomains
	 */
	private static function bot(
		string $id,
		string $name,
		string $category,
		array $userAgentPatterns,
		string $verificationMethod,
		?string $cidrPackSourceKey,
		array $fcrdnsDomains,
		string $description,
	): FirewallBotDefinition {
		return new FirewallBotDefinition($id, $name, $category, $userAgentPatterns, $verificationMethod, $cidrPackSourceKey, $fcrdnsDomains, $description);
	}

	private static function scanner(string $id, string $name): FirewallBotDefinition
	{
		return self::bot($id, $name, 'malicious_scanner', [$name], 'none', null, [], 'Known offensive security tool signature.');
	}
}
