<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.scheduled_task_provider')]
interface ScheduledTaskProviderInterface
{
	public function getKey(): string;

	public function getName(): string;

	/** Return `site` or `global`. */
	public function getScope(): string;

	public function allowsOverlap(): bool;

	/** @return array<string,array{type:string,label:string,required?:bool,default?:mixed}> */
	public function getOptionsSchema(): array;

	/** @param array<string,mixed> $options
	 *  @return array<string,mixed>
	 */
	public function validateOptions(array $options): array;

	/** @param array<string,mixed> $options
	 *  @return array<string,mixed>
	 */
	public function getBatchConfiguration(array $options, int|string|null $siteId): array;

	/** @param array<string,mixed> $options
	 *  @return iterable<BatchMessage>
	 */
	public function createMessages(array $options, int|string|null $siteId): iterable;
}
