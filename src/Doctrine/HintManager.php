<?php
namespace iikiti\CMS\Entity\Object;



class HintManager {

	/** @var array<string,int> */
	private static array $hints = [];

	private function __construct() {}

	public static function getHints(): array {
		return self::$hints;
	}

	public static function getHint(string $key): ?int {
		return self::$hints[$key] ?? null;
	}

	public static function getEraseHint(string $key): ?int {
		$value = self::$hints[$key] ?? null;
		unset(self::$hints[$key]);
 		return $value;
	}

	public static function setHint(string $key, int $value): void {
		self::$hints[$key] = $value;
	}

}
