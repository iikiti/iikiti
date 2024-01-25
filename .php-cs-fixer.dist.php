<?php

$finder = (new PhpCsFixer\Finder())->
	in(__DIR__)->
	exclude('var')
;

return (new PhpCsFixer\Config())->
	setRules([
		'@Symfony' => true,
		'no_unused_imports' => true,
		'operator_linebreak' => ['position' => 'end'],
		'phpdoc_to_comment' => false,
		'class_definition' => [
			'single_line' => false,
		],
	])->
	setFinder($finder)->
	setIndent("\t")
;
