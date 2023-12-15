<?php

namespace iikiti\CMS\Security\Authentication\MultiFactor\User;

class Property
{
	public const KEY = 'mfa';

	public function __construct(
		public string|null $type = null
	) {
	}

	public function __serialize(): array
	{
		$rc = new \ReflectionClass($this);
		$data = [];
		foreach ($rc->getProperties() as $property) {
			$data[$property->getName()] = $property->getValue($this);
		}

		return $data;
	}

	public function __unserialize(array $data): void
	{
		$rc = new \ReflectionClass($this);
		foreach ($data as $key => $value) {
			$prop = $rc->getProperty($key);
			if (false == $prop->isDefault()) {
				throw new \InvalidArgumentException('Property is not defined: '.$key);
			}
			$prop->setValue($this, $value);
		}
	}
}
