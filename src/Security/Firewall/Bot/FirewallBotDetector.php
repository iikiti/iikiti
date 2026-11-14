<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use Symfony\Component\HttpFoundation\Request;

/**
 * Classifies automated clients and selects the bot-policy action for a request.
 *
 * Evaluation order: honeypot traps, verified trusted automations (may bypass WAF),
 * known-bot identity and posture, then browser-header heuristics on public HTML routes.
 * The final action is always constrained so /api/* never receives an HTML challenge.
 */
final readonly class FirewallBotDetector
{
	private const DEFAULT_POSTURE = 'allow';
	private const SUSPICIOUS_ACTION = 'report';
	private const BROWSER_UA_PREFIX = 'Mozilla/5.0';

	public function __construct(
		private FirewallBotCatalog $catalog,
		private FirewallBotVerifier $verifier,
		private FirewallTrustedAutomationRepository $automations,
	) {
	}

	/** @param array<string,mixed> $botsConfig */
	public function evaluate(Request $request, string $clientIp, string $routeGroup, array $botsConfig, int|string|null $siteId): FirewallBotEvaluationResult
	{
		if (true !== ($botsConfig['enabled'] ?? false)) {
			return FirewallBotEvaluationResult::none();
		}

		$honeypot = $this->honeypot($request, $botsConfig);
		if (null !== $honeypot) {
			return $honeypot;
		}

		$trusted = $this->trustedAutomation($request, $clientIp, $siteId);
		if (null !== $trusted) {
			return $trusted;
		}

		$known = $this->knownBot($request, $clientIp, $botsConfig);
		if (null !== $known) {
			return $this->constrain($known, $request);
		}

		return $this->constrain($this->browserHeaderSignal($request, $routeGroup), $request);
	}

	/** @param array<string,mixed> $botsConfig */
	private function honeypot(Request $request, array $botsConfig): ?FirewallBotEvaluationResult
	{
		$honeypotPaths = $botsConfig['honeypotPaths'] ?? [];
		if (true !== ($botsConfig['honeypotEnabled'] ?? false) || !is_array($honeypotPaths)) {
			return null;
		}
		if (!in_array($request->getPathInfo(), $honeypotPaths, true)) {
			return null;
		}

		$action = $this->normalizeAction($botsConfig['honeypotAction'] ?? 'block', 'block');

		return new FirewallBotEvaluationResult(
			action: $action,
			category: 'malicious_scanner',
			findings: [new FirewallFinding('bot:honeypot', 'Request reached a bot honeypot path.', $this->findingAction($action))],
		);
	}

	private function trustedAutomation(Request $request, string $clientIp, int|string|null $siteId): ?FirewallBotEvaluationResult
	{
		$candidates = $this->automations->findForSiteAndGlobal($siteId);
		$automation = $this->verifier->findTrustedAutomation($request, $clientIp, $candidates);
		if (null === $automation) {
			return null;
		}

		return new FirewallBotEvaluationResult(
			action: 'allow',
			category: $automation->getCategory(),
			botId: 'automation:'.$automation->getHandle(),
			botName: $automation->getName(),
			verified: true,
			bypassWaf: $automation->isBypassWaf(),
			trustedAutomationId: $automation->getHandle(),
			rateLimitOverride: $automation->getRateLimitOverride(),
		);
	}

	/** @param array<string,mixed> $botsConfig */
	private function knownBot(Request $request, string $clientIp, array $botsConfig): ?FirewallBotEvaluationResult
	{
		$userAgent = (string) $request->headers->get('User-Agent', '');
		$bot = $this->catalog->matchUserAgent($userAgent);
		if (null === $bot) {
			return null;
		}

		$identity = $this->verifier->verifyKnownBot($bot, $clientIp, $botsConfig);
		$findings = [];
		$action = $this->postureFor($bot, $botsConfig);

		if ($identity['impersonated']) {
			$findings[] = new FirewallFinding('bot:impersonation', sprintf('Client claims %s but failed identity verification.', $bot->name), FirewallAction::Report);
			if (true === ($botsConfig['blockImpersonators'] ?? false)) {
				$action = $this->normalizeAction($botsConfig['impersonationAction'] ?? 'block', 'block');
			}
		} elseif ('allow' !== $action) {
			$findings[] = new FirewallFinding(
				'bot:'.$bot->id,
				sprintf('Bot policy matched %s (%s: %s).', $bot->name, $bot->category, $action),
				$this->findingAction($action),
			);
		}

		return new FirewallBotEvaluationResult(
			action: $action,
			category: $bot->category,
			botId: $bot->id,
			botName: $bot->name,
			verified: $identity['verified'],
			impersonated: $identity['impersonated'],
			findings: $findings,
		);
	}

	/** @param array<string,mixed> $botsConfig */
	private function postureFor(FirewallBotDefinition $bot, array $botsConfig): string
	{
		$overrides = is_array($botsConfig['botOverrides'] ?? null) ? $botsConfig['botOverrides'] : [];
		$postures = is_array($botsConfig['categoryPostures'] ?? null) ? $botsConfig['categoryPostures'] : [];

		return $this->normalizeAction($overrides[$bot->id] ?? $postures[$bot->category] ?? self::DEFAULT_POSTURE, self::DEFAULT_POSTURE);
	}

	/**
	 * Heuristic for claimed desktop browsers on public HTML routes that omit the headers
	 * every real browser sends. Reported rather than blocked by default.
	 */
	private function browserHeaderSignal(Request $request, string $routeGroup): FirewallBotEvaluationResult
	{
		if ('public' !== $routeGroup || !$this->claimsBrowser($request)) {
			return FirewallBotEvaluationResult::none();
		}
		if ($request->headers->has('Accept') && $request->headers->has('Accept-Language')) {
			return FirewallBotEvaluationResult::none();
		}

		return new FirewallBotEvaluationResult(
			action: self::SUSPICIOUS_ACTION,
			category: 'malicious_scanner',
			findings: [new FirewallFinding('bot:header-inconsistency', 'Browser User-Agent without standard Accept headers.', FirewallAction::Report)],
		);
	}

	private function claimsBrowser(Request $request): bool
	{
		$userAgent = (string) $request->headers->get('User-Agent', '');

		return str_starts_with($userAgent, self::BROWSER_UA_PREFIX);
	}

	/**
	 * Applies route constraints: API paths never receive a challenge page.
	 */
	private function constrain(FirewallBotEvaluationResult $result, Request $request): FirewallBotEvaluationResult
	{
		if ('challenge' === $result->action && $this->isApiPath($request->getPathInfo())) {
			return $result->withAction('block');
		}

		return $result;
	}

	private function isApiPath(string $path): bool
	{
		return '/api' === $path || str_starts_with($path, '/api/');
	}

	/** Maps a configured posture onto the bot evaluation action, rejecting unknown values. */
	private function normalizeAction(mixed $value, string $fallback): string
	{
		if (!is_string($value) || !in_array($value, ['allow', 'throttle', 'report', 'challenge', 'block'], true)) {
			return $fallback;
		}

		return $value;
	}

	private function findingAction(string $action): FirewallAction
	{
		return 'block' === $action || 'challenge' === $action ? FirewallAction::Deny : FirewallAction::Report;
	}
}
