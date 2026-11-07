<?php

declare(strict_types=1);

namespace iikiti\CMS\Profiler;

use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/** Safe, request-scoped diagnostics for the dynamic web application firewall. */
final class FirewallDataCollector extends DataCollector implements EventSubscriberInterface
{
	private const MAX_FINDINGS = 100;
	private const MAX_MATCHED_CONDITIONS = 64;

	public static function getSubscribedEvents(): array
	{
		return [FirewallSecurityEvent::NAME => 'onFirewallSecurityEvent'];
	}

	public function getName(): string
	{
		return 'iikiti.firewall';
	}

	public function onFirewallSecurityEvent(FirewallSecurityEvent $event): void
	{
		$findings = array_map($this->safeFinding(...), array_slice($event->findings, 0, self::MAX_FINDINGS));
		$truncated = count($event->findings) > self::MAX_FINDINGS;
		foreach ($findings as $finding) {
			if ($finding['conditions_truncated']) {
				$truncated = true;
			}
		}

		$this->data = [
			'evaluated' => true,
			'request' => [
				'method' => $this->boundedString($event->requestSummary['method'], 32),
				'path' => $this->boundedString($event->requestSummary['path'], 2048),
				'site_id' => is_string($event->requestSummary['siteId'])
					? $this->boundedString($event->requestSummary['siteId'], 128)
					: $event->requestSummary['siteId'],
				'client_ip' => $this->boundedString($event->requestSummary['clientIp'], 128),
			],
			'action' => $event->action->value,
			'findings' => $findings,
			'truncated' => $truncated,
		];
	}

	public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
	{
		$data = $this->currentData();
		$data['response_status'] = $response->getStatusCode();
		$this->data = $data;
	}

	public function isEvaluated(): bool
	{
		return (bool) ($this->currentData()['evaluated'] ?? false);
	}

	public function getOutcome(): string
	{
		$data = $this->currentData();
		if (!(bool) ($data['evaluated'] ?? false)) {
			return 'not_evaluated';
		}

		if (FirewallAction::Deny->value === ($data['action'] ?? null)) {
			return 'blocked';
		}

		return [] === ($data['findings'] ?? []) ? 'no_match' : 'reported';
	}

	/** @return array{method:string,path:string,site_id:int|string|null,client_ip:string}|null */
	public function getRequestSummary(): ?array
	{
		$request = $this->currentData()['request'] ?? null;

		return is_array($request) ? $request : null;
	}

	public function getAction(): ?string
	{
		$action = $this->currentData()['action'] ?? null;

		return is_string($action) ? $action : null;
	}

	public function getResponseStatus(): ?int
	{
		$status = $this->currentData()['response_status'] ?? null;

		return is_int($status) ? $status : null;
	}

	/** @return list<array{rule_id:string,name:string,action:string,priority:int,matched_conditions:list<array<string,mixed>>,conditions_truncated:bool}> */
	public function getFindings(): array
	{
		$findings = $this->currentData()['findings'] ?? [];

		return array_values(is_array($findings) ? $findings : []);
	}

	public function isFindingsTruncated(): bool
	{
		return (bool) ($this->currentData()['truncated'] ?? false);
	}

	public function reset(): void
	{
		$this->data = [];
	}

	/** @return array{rule_id:string,name:string,action:string,priority:int,matched_conditions:list<array<string,mixed>>,conditions_truncated:bool} */
	private function safeFinding(FirewallFinding $finding): array
	{
		$matchedConditions = array_map(
			$this->safeCondition(...),
			array_slice($finding->matchedConditions, 0, self::MAX_MATCHED_CONDITIONS),
		);

		return [
			'rule_id' => $this->boundedString($finding->ruleId, 128),
			'name' => $this->boundedString($finding->message, 256),
			'action' => $finding->action->value,
			'priority' => $finding->priority,
			'matched_conditions' => $matchedConditions,
			'conditions_truncated' => count($finding->matchedConditions) > self::MAX_MATCHED_CONDITIONS,
		];
	}

	/** @param array<string,mixed> $condition
	 * @return array<string,mixed>
	 */
	private function safeCondition(array $condition): array
	{
		$safe = [];
		foreach (['condition', 'target', 'name', 'operator'] as $key) {
			if (is_string($condition[$key] ?? null)) {
				$safe[$key] = $this->boundedString($condition[$key], 128);
			}
		}
		if (is_array($condition['transformations'] ?? null)) {
			$transformations = array_values(array_filter(
				array_slice($condition['transformations'], 0, 8),
				static fn (mixed $value): bool => is_string($value),
			));
			$safe['transformations'] = array_map(fn (string $value): string => $this->boundedString($value, 64), $transformations);
		}

		return $safe;
	}

	private function boundedString(string $value, int $limit): string
	{
		return strlen($value) > $limit ? substr($value, 0, $limit).'...' : $value;
	}

	/** @return array<string,mixed> */
	private function currentData(): array
	{
		return is_array($this->data) ? $this->data : [];
	}
}
