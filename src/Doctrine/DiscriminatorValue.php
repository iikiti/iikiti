<?php

namespace iikiti\CMS\Doctrine;

#[Attribute]
class DiscriminatorValue {

	public function __construct( protected string $value ) {
		
	}

	public function getValue(): string {
		return strtolower($this->value);
	}

}
