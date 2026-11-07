<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final class CoreFirewallRules
{
	/** @return list<array<string,mixed>> */
	public function getRules(): array
	{
		return [
			[
				'id' => 'core:owasp-sqli',
				'name' => 'Suspicious SQL injection syntax',
				'action' => 'report',
				'conditions' => ['any' => $this->textMatches(['query', 'field', 'body', 'cookie', 'upload'], '(?:union\\s+(?:all\\s+)?select|select\\s+.{1,80}\\s+from|(?:sleep|benchmark)\\s*\\(|(?:--|#)\\s)')],
			],
			[
				'id' => 'core:owasp-xss',
				'name' => 'Suspicious cross-site scripting syntax',
				'action' => 'report',
				'conditions' => ['any' => $this->textMatches(['uri', 'query', 'field', 'body', 'cookie', 'upload'], '(?:<\\s*script\\b|javascript\\s*:|on[a-z]{2,32}\\s*=)')],
			],
			[
				'id' => 'core:owasp-path-traversal',
				'name' => 'Suspicious path traversal syntax',
				'action' => 'report',
				'conditions' => ['any' => $this->textMatches(['uri', 'query', 'field'], '(?:\\.\\.|%(?:25)?2e){1,2}(?:[/\\\\]|%(?:25)?(?:2f|5c))')],
			],
		];
	}

	/**
	 * @param list<string> $targets
	 *
	 * @return list<array{condition:string,options:array{target:string,operator:string,value:string}}>
	 */
	private function textMatches(array $targets, string $pattern): array
	{
		return array_map(static fn (string $target): array => [
			'condition' => 'request.match',
			'options' => ['target' => $target, 'operator' => 'regex', 'value' => $pattern],
		], $targets);
	}
}
