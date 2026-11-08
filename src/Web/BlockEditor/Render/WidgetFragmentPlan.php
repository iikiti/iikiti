<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/** A deterministic, render-revision-bound plan of independently rendered widgets. */
final class WidgetFragmentPlan
{
	/**
	 * @param list<array{kind:string,id:string,role:string,slot:string,depth:int}> $descriptors
	 * @param array<string,array{node:array<string,mixed>,path:string,root_kind:string,root_id:string,depth:int,descriptor:array{kind:string,id:string,role:string,slot:string,depth:int}}> $entriesById
	 * @param array<string,array{kind:string,id:string,role:string,slot:string,depth:int}> $descriptorsByPath
	 */
	public function __construct(
		private readonly array $descriptors,
		private readonly array $entriesById,
		private readonly array $descriptorsByPath,
	) {
	}

	/** @return list<array{kind:string,id:string,role:string,slot:string,depth:int}> */
	public function descriptors(): array
	{
		return $this->descriptors;
	}

	/** @return array{node:array<string,mixed>,path:string,root_kind:string,root_id:string,depth:int,descriptor:array{kind:string,id:string,role:string,slot:string,depth:int}}|null */
	public function entry(string $id): ?array
	{
		return $this->entriesById[$id] ?? null;
	}

	/** @return array{kind:string,id:string,role:string,slot:string,depth:int}|null */
	public function descriptorAtPath(string $path): ?array
	{
		return $this->descriptorsByPath[$path] ?? null;
	}

	public static function rootPath(string $kind, string $id): string
	{
		return $kind.':'.strlen($id).':'.$id;
	}

	public static function childPath(string $parentPath, int $index): string
	{
		return $parentPath.'/children/'.$index;
	}
}
