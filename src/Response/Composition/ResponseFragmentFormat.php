<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

enum ResponseFragmentFormat: string
{
	case Html = 'html';
	case JsonObject = 'json-object';
	case JsonArray = 'json-array';
	case Text = 'text';
}
