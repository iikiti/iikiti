<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Thrown for UNION query problems, such as adding an additional part before an
 * initial part exists.
 */
class UnionException extends QueryBuilderException
{
	public static function missingInitialPart(): self
	{
		return new self(
			'No initial UNION part set. Use unionPart() to set the first part before addUnionPart().'
		);
	}
}
