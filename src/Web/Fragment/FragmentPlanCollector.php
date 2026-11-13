<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

/** Collects Twig-declared fragments and validates their per-page dependency DAG. */
final class FragmentPlanCollector
{
	/** @var array<string,array{key:string,provider:string,params:array<array-key,mixed>,delivery:string,dependsOn:list<string>,slot:?string}> */
	private array $placements = [];

	/**
	 * @param array<array-key,mixed> $params
	 * @param array<string,mixed> $options
	 */
	public function declare(string $provider, array $params, array $options): string
	{
		if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.[a-z][a-z0-9_.-]{0,63}$/D', $provider)) {
			throw new \InvalidArgumentException('Fragment provider ID is invalid.');
		}
		if ([] !== $params && array_is_list($params)) {
			throw new \InvalidArgumentException('Fragment parameters must be a JSON object.');
		}
		if (16_384 < strlen(json_encode($params, JSON_THROW_ON_ERROR, 16))) {
			throw new \InvalidArgumentException('Fragment parameters exceed the request size limit.');
		}
		if (count($this->placements) >= 128) {
			throw new \InvalidArgumentException('A page cannot declare more than 128 fragments.');
		}
		$delivery = $options['delivery'] ?? 'server';
		if (!in_array($delivery, ['server', 'browser'], true)) {
			throw new \InvalidArgumentException('Fragment delivery must be "server" or "browser".');
		}
		$key = $options['key'] ?? 'fragment-'.bin2hex(random_bytes(8));
		$dependsOn = $options['dependsOn'] ?? [];
		if (!is_string($key) || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) ||
			!is_array($dependsOn) || !array_is_list($dependsOn)) {
			throw new \InvalidArgumentException('Fragment placement keys or dependencies are invalid.');
		}
		foreach ($dependsOn as $dependency) {
			if (!is_string($dependency) || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $dependency) || $dependency === $key) {
				throw new \InvalidArgumentException('Fragment dependency keys are invalid.');
			}
		}
		if (isset($this->placements[$key])) {
			throw new \InvalidArgumentException(sprintf('Fragment placement key "%s" is duplicated.', $key));
		}
		$this->placements[$key] = [
			'key' => $key,
			'provider' => $provider,
			'params' => $params,
			'delivery' => $delivery,
			'dependsOn' => array_values(array_unique($dependsOn)),
			'slot' => 'server' === $delivery ? bin2hex(random_bytes(16)) : null,
		];

		if ('browser' === $delivery) {
			return $this->browserPlaceholder($provider, $params, $key, $dependsOn);
		}

		$slot = $this->placements[$key]['slot'];

		return sprintf('<template data-iikiti-provider-slot="%s"></template>', $slot);
	}

	/** @return list<array{kind:string,id:string,role:string,slot:string,depth:int,key:string,dependsOn:list<string>,params:array<array-key,mixed>}> */
	public function serverDescriptors(): array
	{
		$this->validate();
		$descriptors = [];
		foreach ($this->placements as $placement) {
			if ('server' !== $placement['delivery']) {
				continue;
			}
			$descriptors[] = [
				'kind' => 'provider',
				'id' => $placement['provider'],
				'role' => 'provider',
				'slot' => (string) $placement['slot'],
				'depth' => 0,
				'key' => $placement['key'],
				'dependsOn' => $placement['dependsOn'],
				'params' => $placement['params'],
			];
		}

		return $descriptors;
	}

	/** @param array<array-key,mixed> $params
	 * @param list<string> $dependsOn
	 */
	public static function browserPlaceholder(string $provider, array $params, string $key, array $dependsOn): string
	{
		if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.[a-z][a-z0-9_.-]{0,63}$/D', $provider) ||
			1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) ||
			([] !== $params && array_is_list($params)) ||
			16_384 < strlen(json_encode($params, JSON_THROW_ON_ERROR, 16))) {
			throw new \InvalidArgumentException('Browser fragment descriptor values are invalid.');
		}
		foreach ($dependsOn as $dependency) {
			if (1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $dependency) || $dependency === $key) {
				throw new \InvalidArgumentException('Browser fragment dependency keys are invalid.');
			}
		}
		$descriptor = json_encode([
			'provider' => $provider,
			'params' => $params,
			'id' => $key,
			'dependsOn' => $dependsOn,
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

		return sprintf(
			'<div data-iikiti-fragment-descriptor="%s"></div>',
			htmlspecialchars($descriptor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
		);
	}

	private function validate(): void
	{
		foreach ($this->placements as $placement) {
			foreach ($placement['dependsOn'] as $dependencyKey) {
				$dependency = $this->placements[$dependencyKey] ?? null;
				if (null === $dependency) {
					throw new \InvalidArgumentException(sprintf('Fragment placement "%s" depends on unknown key "%s".', $placement['key'], $dependencyKey));
				}
				if ('server' === $placement['delivery'] && 'browser' === $dependency['delivery']) {
					throw new \InvalidArgumentException(sprintf('Server fragment "%s" cannot depend on browser fragment "%s".', $placement['key'], $dependencyKey));
				}
			}
		}

		$visited = [];
		$visiting = [];
		$visit = function (string $key) use (&$visit, &$visited, &$visiting): void {
			if (isset($visiting[$key])) {
				throw new \InvalidArgumentException('Fragment placement dependencies contain a cycle.');
			}
			if (isset($visited[$key])) {
				return;
			}
			$visiting[$key] = true;
			foreach ($this->placements[$key]['dependsOn'] as $dependency) {
				$visit($dependency);
			}
			unset($visiting[$key]);
			$visited[$key] = true;
		};
		foreach (array_keys($this->placements) as $key) {
			$visit($key);
		}
	}
}
