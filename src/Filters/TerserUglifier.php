<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final readonly class TerserUglifier
{
	private const SCRIPT = <<<'JS'
		import { minify } from 'terser';
		let input = '';
		for await (const chunk of process.stdin) input += chunk;
		const sources = JSON.parse(input);
		const results = [];
		for (const source of sources) {
			try {
				const result = await minify(source, { compress: true, mangle: true });
				results.push(result.code ?? source);
			} catch {
				results.push(source);
			}
		}
		process.stdout.write(JSON.stringify(results));
		JS;

	public function __construct(
		#[Autowire('%kernel.project_dir%')]
		private string $projectDirectory,
	) {
	}

	/** @param list<string> $sources
	 *  @return list<string>
	 */
	public function minifyAll(array $sources): array
	{
		if ([] === $sources) {
			return [];
		}
		$process = new Process(
			['node', '--input-type=module', '-e', self::SCRIPT],
			$this->projectDirectory,
		);
		$process->setInput(json_encode($sources, JSON_THROW_ON_ERROR));
		$process->setTimeout(5);
		$process->mustRun();
		$results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($results) || !array_is_list($results) || count($results) !== count($sources)) {
			throw new \UnexpectedValueException('Terser returned an invalid result list.');
		}
		foreach ($results as $result) {
			if (!is_string($result)) {
				throw new \UnexpectedValueException('Terser returned a non-string result.');
			}
		}

		return $results;
	}
}
